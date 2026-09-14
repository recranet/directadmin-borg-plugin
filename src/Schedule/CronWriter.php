<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Schedule;

use Recranet\DirectAdminBorg\Config\Configuration;
use Recranet\DirectAdminBorg\Paths;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Owns /etc/cron.d/directadmin-borg.
 *
 * The file is rewritten from the configuration whenever settings are saved, and
 * removed when scheduling is switched off, so the UI is always the truth. It is
 * never appended to: a partially rewritten cron file is worse than no schedule.
 */
final class CronWriter
{
    public function __construct(
        private readonly string $pluginDir,
        private readonly Paths $paths,
        private readonly Filesystem $filesystem
    ) {
    }

    public function apply(Configuration $config): void
    {
        if (!$config->scheduleEnabled()) {
            $this->remove();

            return;
        }

        $php = (new PhpExecutableFinder())->find(false) ?: PHP_BINARY;
        $command = sprintf('%s %s/bin/console borg:scheduled-backup', $php, $this->pluginDir);

        $lines = [
            '# Managed by the DirectAdmin Borg plugin.',
            '# Rewritten whenever the schedule is saved in the DirectAdmin UI;',
            '# edit the schedule there rather than here.',
            'SHELL=/bin/sh',
            'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'MAILTO=""',
        ];

        // The plugin's own state locations have to reach cron, which starts
        // with an almost empty environment.
        foreach (Paths::FORWARDED_ENV as $name) {
            $value = getenv($name);
            if (\is_string($value) && $value !== '') {
                $lines[] = sprintf('%s=%s', $name, $value);
            }
        }

        $lines[] = sprintf('%s %s * * * root %s', $config->scheduleMinute(), $config->scheduleHour(), $command);
        $lines[] = '';

        $this->filesystem->dumpFile($this->paths->cronFile, implode("\n", $lines));
        // cron refuses to read a file that is group- or world-writable.
        $this->filesystem->chmod($this->paths->cronFile, 0644);
    }

    public function remove(): void
    {
        $this->filesystem->remove($this->paths->cronFile);
    }

    public function isInstalled(): bool
    {
        return is_file($this->paths->cronFile);
    }

    public function file(): string
    {
        return $this->paths->cronFile;
    }
}
