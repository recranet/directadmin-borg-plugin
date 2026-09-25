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

    /**
     * Run borg with its stdout piped straight into another process.
     *
     * For handing archive contents to a process that is not root: borg has to
     * run as root to reach the repository, and the consumer must not, so the
     * two are separate processes joined by a pipe the kernel owns. The bytes
     * never pass through PHP, which matters when the stream is a whole home
     * directory.
     *
     * proc_open rather than Symfony Process because the consumer's environment
     * has to be exactly $consumerEnv. Process merges the parent environment
     * into whatever it is given, and here that would put anything the worker
     * inherited into a process the customer can inspect.
     *
     * The result is borg's, unless the consumer failed: then it is a failure
     * carrying the consumer's stderr, since "tar: Cannot open: Permission
     * denied" is the line that says what went wrong. Collected stderr keeps its
     * last 64 KB -- with --list every restored path passes through it.
     *
     * @param string[]             $arguments   borg arguments
     * @param string[]             $consumer    command line, absolute path first
     * @param array<string,string> $consumerEnv the consumer's entire environment
     * @param callable|null        $onOutput    receives (string $type, string $chunk)
     */
    public function runInto(
        array $arguments,
        array $consumer,
        array $consumerEnv,
        ?callable $onOutput = null,
    ): BorgResult {
        $command = array_merge([$this->binary], array_values(array_map('strval', $arguments)));
        $commandLine = implode(' ', array_map('escapeshellarg', $command)) . ' | '
            . implode(' ', array_map('escapeshellarg', $consumer));

        $borg = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $borgPipes, '/', $this->environment());
        if (!\is_resource($borg)) {
            return new BorgResult(2, '', 'Could not start borg.', $commandLine);
        }

        $target = proc_open(array_values($consumer), [0 => $borgPipes[1], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $targetPipes, '/', $consumerEnv);

        // The consumer holds the read end now. Keeping a copy here would mean
        // borg never sees the pipe close if the consumer dies, and blocks.
        fclose($borgPipes[1]);

        if (!\is_resource($target)) {
            fclose($borgPipes[2]);
            proc_terminate($borg);
            proc_close($borg);

            return new BorgResult(2, '', 'Could not start ' . basename($consumer[0] ?? 'the restore') . '.', $commandLine);
        }

        $streams = ['borg' => $borgPipes[2], 'out' => $targetPipes[1], 'err' => $targetPipes[2]];
        $collected = ['borg' => '', 'out' => '', 'err' => ''];
        foreach ($streams as $stream) {
            stream_set_blocking($stream, false);
        }

        while ($streams !== []) {
            $read = array_values($streams);
            $write = $except = null;
            if (@stream_select($read, $write, $except, 1) === false) {
                break;
            }

            foreach ($streams as $key => $stream) {
                if (!\in_array($stream, $read, true)) {
                    continue;
                }

                $chunk = fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && feof($stream))) {
                    fclose($stream);
                    unset($streams[$key]);
                    continue;
                }

                $collected[$key] = substr($collected[$key] . $chunk, -65536);
                if ($onOutput !== null && $chunk !== '') {
                    $onOutput($key === 'out' ? 'out' : 'err', $chunk);
                }
            }
        }

        $borgExit = proc_close($borg);
        $targetExit = proc_close($target);

        if ($targetExit !== 0) {
            return new BorgResult(
                2,
                '',
                trim($collected['err'] . "\n" . $collected['borg']) ?: \sprintf('%s exited with code %d.', basename($consumer[0] ?? 'the restore'), $targetExit),
                $commandLine
            );
        }

        return new BorgResult($borgExit, '', $collected['borg'], $commandLine);
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
