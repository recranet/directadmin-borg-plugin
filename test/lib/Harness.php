<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Test;

use Recranet\DirectAdminBorg\Http\PluginRequest;
use Recranet\DirectAdminBorg\Plugin;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Test harness.
 *
 * Deliberately not PHPUnit: the suite has to run inside a bare container next
 * to a real borg, and half of what it asserts is process- and permission-level
 * behaviour that is driven through the plugin's own entry points rather than
 * through its classes.
 */
final class Harness
{
    private int $passed = 0;
    private int $failed = 0;

    /** @var string[] */
    private array $failures = [];

    private Filesystem $filesystem;

    public function __construct(
        private readonly string $pluginDir,
        private readonly string $dataDir = '/tmp/borg-plugin-test-data',
    ) {
        $this->filesystem = new Filesystem();
    }

    /** A clean slate, so no test can depend on a previous run. */
    public function reset(): Plugin
    {
        $this->filesystem->remove([
            $this->dataDir,
            '/backup/test-repo',
            '/backup/probe-root',
            '/backup/probe-admin',
            '/backup/symlink-repo',
            '/home/alice/plant',
            '/etc/borg-plant',
            '/home/alice/borg_restore',
            '/home/alice/backups',
            '/tmp/pwned',
            '/tmp/borg-admin-home',
        ]);

        foreach ($this->environment() as $name => $value) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }

        return Plugin::boot($this->pluginDir);
    }

    /** @return array<string,string> */
    public function environment(): array
    {
        return [
            'BORG_PLUGIN_DATA_DIR' => $this->dataDir,
            'BORG_PLUGIN_HOME'     => '/root',
        ];
    }

    // ------------------------------------------------------------ assertions

    public function group(string $name): void
    {
        echo "\n\033[1m" . $name . "\033[0m\n";
    }

    public function ok(bool $condition, string $message): void
    {
        $this->record($condition, $message);
    }

    public function notOk(bool $condition, string $message): void
    {
        $this->record(!$condition, $message);
    }

    public function is(mixed $actual, mixed $expected, string $message): void
    {
        $this->record(
            $actual === $expected,
            $message,
            \sprintf('expected %s, got %s', var_export($expected, true), var_export($actual, true))
        );
    }

    /** @param array<int|string,mixed> $value */
    public function isEmpty(array $value, string $message): void
    {
        $this->record($value === [], $message, 'got: ' . implode('; ', array_map('strval', $value)));
    }

    /** @param array<int|string,mixed> $value */
    public function notEmpty(array $value, string $message): void
    {
        $this->record($value !== [], $message, 'expected at least one validation error');
    }

    public function contains(string $haystack, string $needle, string $message): void
    {
        $this->record(str_contains($haystack, $needle), $message, 'output did not contain: ' . $needle);
    }

    public function notContains(string $haystack, string $needle, string $message): void
    {
        $this->record(!str_contains($haystack, $needle), $message, 'output unexpectedly contained: ' . $needle);
    }

    public function throws(callable $fn, string $message): void
    {
        try {
            $fn();
            $this->record(false, $message, 'no exception was thrown');
        } catch (\Throwable) {
            $this->record(true, $message);
        }
    }

    private function record(bool $passed, string $message, string $detail = ''): void
    {
        if ($passed) {
            ++$this->passed;
            echo "  \033[32mPASS\033[0m " . $message . "\n";

            return;
        }

        ++$this->failed;
        $this->failures[] = $message . ($detail !== '' ? ' (' . $detail . ')' : '');
        echo "  \033[31mFAIL\033[0m " . $message . ($detail !== '' ? "\n       " . $detail : '') . "\n";
    }

    public function summary(): int
    {
        $total = $this->passed + $this->failed;
        echo "\n" . str_repeat('-', 64) . "\n";

        if ($this->failed === 0) {
            echo "\033[32mAll " . $total . " checks passed.\033[0m\n";

            return 0;
        }

        echo "\033[31m" . $this->failed . ' of ' . $total . " checks failed:\033[0m\n";
        foreach ($this->failures as $failure) {
            echo '  - ' . $failure . "\n";
        }

        return 1;
    }

    // --------------------------------------------------------------- driving

    /**
     * A request object, as DirectAdmin would present it.
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     */
    public function request(
        string $level,
        string $username = 'admin',
        array $query = [],
        array $body = [],
        string $method = 'GET',
    ): PluginRequest {
        return PluginRequest::fromArrays($level, $username, $query, $body, $method);
    }

    /**
     * Execute a plugin entry point in its own process, exactly as DirectAdmin
     * does: environment in, stdout out. This is what makes the suite cover the
     * env-decoding path and the real shebang rather than just the classes.
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     *
     * @return array{stdout:string,stderr:string,exit:int}
     */
    public function invoke(
        string $level,
        string $script = 'index.html',
        string $username = 'admin',
        array $query = [],
        array $body = [],
        string $method = 'GET',
    ): array {
        return $this->exec(
            [$this->pluginDir . '/' . $level . '/' . $script],
            $this->environment() + [
                'USERNAME'       => $username,
                'REQUEST_METHOD' => $method,
                // DirectAdmin HTML-entity encodes the values it exports, so the
                // harness must too or the decoding path would go untested.
                'QUERY_STRING' => $this->encode($query),
                'POST'         => $this->encode($body),
                'REMOTE_ADDR'  => '127.0.0.1',
            ]
        );
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     */
    public function page(string $level, string $username = 'admin', array $query = [], array $body = [], string $method = 'GET'): string
    {
        return $this->invoke($level, 'index.html', $username, $query, $body, $method)['stdout'];
    }

    /**
     * Run a console command in the foreground.
     *
     * @param string[] $arguments
     *
     * @return array{stdout:string,stderr:string,exit:int}
     */
    public function console(array $arguments): array
    {
        return $this->exec(
            array_merge([\PHP_BINARY, '-n', $this->pluginDir . '/bin/console'], $arguments),
            $this->environment() + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']
        );
    }

    /** @param array<string,mixed> $params */
    private function encode(array $params): string
    {
        return $params === [] ? '' : htmlspecialchars(http_build_query($params), \ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param string[]             $command
     * @param array<string,string> $environment
     *
     * @return array{stdout:string,stderr:string,exit:int}
     */
    public function exec(array $command, array $environment = [], ?string $cwd = null): array
    {
        $process = new Process($command, $cwd ?? $this->pluginDir, $environment + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'], null, 900.0);
        $process->run();

        return [
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exit'   => (int) $process->getExitCode(),
        ];
    }

    /** Wait for a detached job to reach a finished state. */
    public function waitForJob(Plugin $plugin, string $jobId, int $seconds = 120): ?\Recranet\DirectAdminBorg\Job\Job
    {
        $deadline = time() + $seconds;

        while (time() < $deadline) {
            $job = $plugin->jobs()->find($jobId);
            if ($job !== null && $job->isFinished()) {
                return $job;
            }
            usleep(250000);
        }

        return $plugin->jobs()->find($jobId);
    }
}
