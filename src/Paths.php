<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Every filesystem location the plugin uses.
 *
 * State lives outside the plugin directory because DirectAdmin replaces the
 * whole plugin tree on update, which would otherwise take the repository
 * config, passphrase and job history with it. Each location can be overridden
 * through the environment so the test suite never touches real paths.
 *
 * Plain methods rather than property hooks: DirectAdmin runs plugin scripts on
 * whatever CLI binary /usr/local/bin/php points at, which CustomBuild lets an
 * administrator pin as far back as PHP 5.6. This plugin requires 8.1, so
 * nothing here may use 8.2+ syntax.
 */
final class Paths
{
    /** Environment variable names that must reach a detached child process. */
    public const FORWARDED_ENV = [
        'BORG_PLUGIN_DATA_DIR',
        'BORG_PLUGIN_CRON_FILE',
        'BORG_PLUGIN_PASSWD_FILE',
        'BORG_PLUGIN_DA_USERS_DIR',
        'BORG_PLUGIN_HOME',
        'BORG_PLUGIN_BORG_BIN',
    ];

    public function __construct(
        public readonly string $dataDir,
        public readonly string $cronFile,
        public readonly string $passwdFile,
        public readonly string $daUsersDir,
        public readonly string $borgHome,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            self::env('BORG_PLUGIN_DATA_DIR', '/var/lib/directadmin-borg'),
            self::env('BORG_PLUGIN_CRON_FILE', '/etc/cron.d/directadmin-borg'),
            self::env('BORG_PLUGIN_PASSWD_FILE', '/etc/passwd'),
            self::env('BORG_PLUGIN_DA_USERS_DIR', '/usr/local/directadmin/data/users'),
            self::env('BORG_PLUGIN_HOME', '/root'),
        );
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return \is_string($value) && $value !== '' ? $value : $default;
    }

    public function configFile(): string
    {
        return $this->dataDir . '/config.json';
    }

    public function passphraseFile(): string
    {
        return $this->dataDir . '/passphrase';
    }

    public function secretFile(): string
    {
        return $this->dataDir . '/csrf.key';
    }

    public function jobsDir(): string
    {
        return $this->dataDir . '/jobs';
    }

    public function logsDir(): string
    {
        return $this->dataDir . '/logs';
    }

    public function locksDir(): string
    {
        return $this->dataDir . '/locks';
    }

    public function cacheDir(): string
    {
        return $this->dataDir . '/cache';
    }

    public function jobFile(string $id): string
    {
        return $this->jobsDir() . '/' . $id . '.json';
    }

    public function logFile(string $id): string
    {
        return $this->logsDir() . '/' . $id . '.log';
    }

    public function ensure(Filesystem $filesystem): void
    {
        foreach ([$this->dataDir, $this->jobsDir(), $this->logsDir(), $this->locksDir(), $this->cacheDir()] as $dir) {
            $filesystem->mkdir($dir, 0700);
        }
        $filesystem->chmod($this->dataDir, 0700);
    }
}
