<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Borg;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Executes the borg CLI.
 *
 * Symfony Process is given an argument array, so repository names, archive
 * names and file paths from the UI are passed to execve() directly and can
 * never be interpreted as shell syntax. The passphrase travels in the
 * environment rather than on the command line, keeping it out of ps output.
 */
final class BorgRunner
{
    /** Backups have no meaningful upper bound; interactive calls do. */
    public const NO_TIMEOUT = 0;

    private ?string $version = null;
    private bool $versionResolved = false;

    /** @var array<string,string> */
    private array $extraEnv = [];

    public function __construct(
        private readonly string $binary,
        private readonly string $home,
    ) {
    }

    public static function locateBinary(): string
    {
        $override = getenv('BORG_PLUGIN_BORG_BIN');
        if (\is_string($override) && $override !== '' && is_executable($override)) {
            return $override;
        }

        $found = (new ExecutableFinder())->find('borg', null, ['/usr/bin', '/usr/local/bin', '/bin', '/opt/borg/bin']);

        return $found ?? '/usr/bin/borg';
    }

    public function binary(): string
    {
        return $this->binary;
    }

    /**
     * Environment applied to every call, e.g. BORG_PASSPHRASE and BORG_RSH.
     *
     * @param array<string,string|null> $env
     */
    public function withEnvironment(array $env): self
    {
        $clone = clone $this;
        $clone->extraEnv = array_map('strval', array_filter($env, static fn ($v) => $v !== null && $v !== ''));

        return $clone;
    }

    public function isInstalled(): bool
    {
        return $this->version() !== null;
    }

    public function version(): ?string
    {
        if ($this->versionResolved) {
            return $this->version;
        }
        $this->versionResolved = true;

        if (!is_executable($this->binary)) {
            return $this->version = null;
        }

        $result = $this->run(['--version'], timeout: 15);
        if (!$result->isSuccessful() || !preg_match('/(\d+\.\d+(?:\.\d+)?)/', $result->stdout, $matches)) {
            return $this->version = null;
        }

        return $this->version = $matches[1];
    }

    /** borg 1.2 renamed --prefix to --glob-archives. */
    public function supportsGlobArchives(): bool
    {
        return $this->atLeast('1.2.0');
    }

    /** `borg compact` only exists from 1.2 onwards. */
    public function supportsCompact(): bool
    {
        return $this->atLeast('1.2.0');
    }

    /** borg 2.x replaced `repo::archive` with `--repo`; this plugin targets 1.x. */
    public function isUnsupportedMajor(): bool
    {
        return $this->atLeast('2.0.0');
    }

    private function atLeast(string $version): bool
    {
        $current = $this->version();

        return $current !== null && version_compare($current, $version, '>=');
    }

    /**
     * @param string[]      $arguments
     * @param callable|null $onOutput  receives (string $type, string $chunk) as
     *                                 borg produces it, for live job logs
     */
    public function run(
        array $arguments,
        int $timeout = 120,
        ?callable $onOutput = null,
        ?string $workingDirectory = null,
    ): BorgResult {
        $process = new Process(
            array_merge([$this->binary], array_values(array_map('strval', $arguments))),
            $workingDirectory ?? '/',
            $this->environment(),
            null,
            $timeout > 0 ? (float) $timeout : null
        );

        // Nothing here reads stdin; leaving it attached would let borg block on
        // an interactive prompt forever.
        $process->setInput('');

        $timedOut = false;
        try {
            $process->run($onOutput === null ? null : static function (string $type, string $chunk) use ($onOutput): void {
                $onOutput($type === Process::OUT ? 'out' : 'err', $chunk);
            });
        } catch (ProcessTimedOutException) {
            $timedOut = true;
        }

        return new BorgResult(
            $timedOut ? 124 : (int) $process->getExitCode(),
            $process->getOutput(),
            $timedOut
                ? trim($process->getErrorOutput() . \sprintf("\nborg timed out after %ds.", $timeout))
                : $process->getErrorOutput(),
            $process->getCommandLine(),
            $timedOut
        );
    }

    /**
     * Run borg and hand stdout to a callback one line at a time.
     *
     * The difference from run() is memory, and it is not a small one: run()
     * buffers the whole of stdout before anyone sees a byte of it. For
     * `borg list` over a real hosting archive that is millions of lines --
     * 2.5 million entries and ~880 MB on the server this was written against --
     * and PHP dies on its memory limit after a minute of work. Nothing is
     * retained here: each chunk is consumed and dropped as it arrives, so the
     * peak is one chunk plus one line however large the archive is.
     *
     * $onLine may return false to stop early; the process is killed and the
     * result is flagged as truncated.
     *
     * stderr is still collected, because it is how borg explains a failure, but
     * it is capped: a repository that prints a warning per file would otherwise
     * reintroduce exactly the problem this method exists to avoid.
     *
     * @param string[] $arguments
     * @param callable $onLine    fn(string $line): bool
     */
    public function runStreaming(
        array $arguments,
        callable $onLine,
        int $timeout = self::NO_TIMEOUT,
    ): BorgResult {
        $process = new Process(
            array_merge([$this->binary], array_values(array_map('strval', $arguments))),
            '/',
            $this->environment(),
            null,
            $timeout > 0 ? (float) $timeout : null
        );
        $process->setInput('');

        $stderr = '';
        $pending = '';
        $stopped = false;
        $timedOut = false;

        $consume = static function (string $line) use ($onLine, &$stopped): void {
            if ($stopped) {
                return;
            }
            if ($onLine($line) === false) {
                $stopped = true;
            }
        };

        try {
            $process->start();

            // The default iterator clears each chunk from the process buffer as
            // it is yielded. ITER_KEEP_OUTPUT would retain it, which is the very
            // thing being avoided.
            foreach ($process as $type => $chunk) {
                if ($type === Process::ERR) {
                    // 64 KB is far more than borg ever needs to say what went
                    // wrong, and bounds a pathological case.
                    if (\strlen($stderr) < 65536) {
                        $stderr .= $chunk;
                    }
                    continue;
                }

                $pending .= $chunk;

                while (($newline = strpos($pending, "\n")) !== false) {
                    $line = substr($pending, 0, $newline);
                    $pending = substr($pending, $newline + 1);
                    $consume($line);
                }

                if ($stopped) {
                    $process->stop(1.0);
                    $pending = '';
                    break;
                }
            }

            // A final line with no trailing newline.
            if (!$stopped && $pending !== '') {
                $consume($pending);
            }
        } catch (ProcessTimedOutException) {
            $timedOut = true;
            $process->stop(1.0);
        }

        $exitCode = $timedOut ? 124 : (int) $process->getExitCode();

        // Killing the process ourselves is not a failure: we got what we asked
        // for and hung up. Report it as success so the caller does not have to
        // special-case a signal exit code.
        if ($stopped) {
            $exitCode = 0;
        }

        return new BorgResult(
            $exitCode,
            '',
            $timedOut
                ? trim($stderr . \sprintf("\nborg timed out after %ds.", $timeout))
                : $stderr,
            $process->getCommandLine(),
            $timedOut
        );
    }

    /** @return array<string,string> */
    private function environment(): array
    {
        return array_merge([
            'PATH'   => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME'   => $this->home,
            'LANG'   => 'C.UTF-8',
            'LC_ALL' => 'C.UTF-8',
            // Without these, borg stops on an interactive y/N prompt and the
            // request hangs until it is killed.
            'BORG_RELOCATED_REPO_ACCESS_IS_OK'           => 'yes',
            'BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK' => 'yes',
            'BORG_HOSTNAME_IS_UNIQUE'                    => 'yes',
        ], $this->extraEnv);
    }
}
