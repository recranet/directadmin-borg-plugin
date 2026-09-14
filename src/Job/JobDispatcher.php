<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Job;

use Recranet\DirectAdminBorg\Exception\BorgPluginException;
use Recranet\DirectAdminBorg\Paths;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Starts a job in a process that outlives the request.
 *
 * A backup easily runs longer than any HTTP request, so the UI records a job
 * and hands it to the console application running detached. Only the job id
 * crosses the boundary; the worker reads its parameters from the job file, so
 * no request data ever reaches a command line.
 */
final class JobDispatcher
{
    public function __construct(
        private readonly string $pluginDir,
        private readonly Paths $paths
    ) {
    }

    public function dispatch(Job $job): void
    {
        $console = $this->pluginDir . '/bin/console';
        if (!is_file($console)) {
            throw new BorgPluginException('Console application is missing: ' . $console);
        }

        $php = (new PhpExecutableFinder())->find(false) ?: PHP_BINARY;

        // Double-fork through sh so the worker becomes a grandchild: Symfony's
        // Process destructor stops any child it still owns, which would kill a
        // backup the moment this request finished. sh exits immediately, the
        // worker is reparented to init, and only the job id — which this plugin
        // generated — is ever interpolated, via "$1"-style placeholders.
        $process = new Process(
            [
                '/bin/sh',
                '-c',
                'nohup "$0" "$1" "$2" "$3" >/dev/null 2>&1 < /dev/null &',
                $php,
                $console,
                'borg:job',
                $job->id,
            ],
            $this->pluginDir,
            $this->environment(),
            null,
            30.0,
        );

        $process->run();

        if (!$process->isSuccessful()) {
            throw new BorgPluginException(
                'Unable to start the background worker: ' . trim($process->getErrorOutput())
            );
        }
    }

    /**
     * Only the plugin's own overrides are forwarded. A child inheriting the
     * full environment of a DirectAdmin request would pick up request data.
     *
     * @return array<string,string>
     */
    private function environment(): array
    {
        $env = ['PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'];

        foreach (Paths::FORWARDED_ENV as $name) {
            $value = getenv($name);
            if (\is_string($value) && $value !== '') {
                $env[$name] = $value;
            }
        }

        return $env;
    }
}
