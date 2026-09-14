<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Config;

/**
 * Immutable view of the plugin configuration.
 *
 * The passphrase is not part of this object's array form: it lives in its own
 * file so the configuration can be read, logged or diffed without leaking it.
 */
final class Configuration
{
    public const DEFAULTS = [
        'repository'           => '',
        'encryption'           => 'repokey-blake2',
        'ssh_command'          => '',
        'archive_prefix'       => '{hostname}-',
        'archive_name'         => '{hostname}-{now:%Y-%m-%d_%H:%M:%S}',
        'source_paths'         => ['/home', '/etc', '/usr/local/directadmin/conf', '/usr/local/directadmin/data/users'],
        'exclude_patterns'     => [
            'sh:/home/*/domains/*/public_html/**/cache/**',
            'sh:/home/*/.cache/**',
            'sh:/home/tmp/**',
            'sh:**/*.sock',
        ],
        'compression'          => 'zstd,6',
        'one_file_system'      => false,
        'prune_enabled'        => true,
        'keep_daily'           => 7,
        'keep_weekly'          => 4,
        'keep_monthly'         => 6,
        'compact_after_prune'  => true,
        'schedule_enabled'     => false,
        'schedule_minute'      => '30',
        'schedule_hour'        => '3',
        'run_after_da_backups' => false,
        'user_restore_enabled' => true,
        'user_restore_dir'     => 'borg_restore',
    ];

    public const ENCRYPTION_MODES = [
        'repokey-blake2'       => 'repokey-blake2 (recommended, key stored in the repository)',
        'repokey'              => 'repokey',
        'keyfile-blake2'       => 'keyfile-blake2 (key stored on this server only)',
        'keyfile'              => 'keyfile',
        'authenticated'        => 'authenticated (no encryption, tamper-evident)',
        'authenticated-blake2' => 'authenticated-blake2',
        'none'                 => 'none (no encryption)',
    ];

    /** Encryption modes that do not need a passphrase. */
    private const PASSPHRASELESS = ['none', 'authenticated', 'authenticated-blake2'];

    /**
     * @param array<string,mixed> $values already validated and merged with defaults
     */
    public function __construct(
        private readonly array $values,
        private readonly string $passphrase
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function withValues(array $values): self
    {
        return new self(array_merge($this->values, $values), $this->passphrase);
    }

    public function isConfigured(): bool
    {
        return trim($this->repository()) !== '';
    }

    public function repository(): string
    {
        return (string) $this->get('repository');
    }

    public function encryption(): string
    {
        return (string) $this->get('encryption');
    }

    public function sshCommand(): string
    {
        return (string) $this->get('ssh_command');
    }

    public function archiveName(): string
    {
        return (string) $this->get('archive_name');
    }

    public function archivePrefix(): string
    {
        return (string) $this->get('archive_prefix');
    }

    /** @return string[] */
    public function sourcePaths(): array
    {
        return array_map('strval', (array) $this->get('source_paths'));
    }

    /** @return string[] */
    public function excludePatterns(): array
    {
        return array_map('strval', (array) $this->get('exclude_patterns'));
    }

    public function compression(): string
    {
        return (string) $this->get('compression');
    }

    public function oneFileSystem(): bool
    {
        return (bool) $this->get('one_file_system');
    }

    public function pruneEnabled(): bool
    {
        return (bool) $this->get('prune_enabled');
    }

    public function keepDaily(): int
    {
        return (int) $this->get('keep_daily');
    }

    public function keepWeekly(): int
    {
        return (int) $this->get('keep_weekly');
    }

    public function keepMonthly(): int
    {
        return (int) $this->get('keep_monthly');
    }

    public function compactAfterPrune(): bool
    {
        return (bool) $this->get('compact_after_prune');
    }

    public function scheduleEnabled(): bool
    {
        return (bool) $this->get('schedule_enabled');
    }

    public function scheduleMinute(): string
    {
        return (string) $this->get('schedule_minute');
    }

    public function scheduleHour(): string
    {
        return (string) $this->get('schedule_hour');
    }

    public function runAfterDaBackups(): bool
    {
        return (bool) $this->get('run_after_da_backups');
    }

    public function userRestoreEnabled(): bool
    {
        return (bool) $this->get('user_restore_enabled');
    }

    public function userRestoreDir(): string
    {
        return (string) $this->get('user_restore_dir');
    }

    public function passphrase(): string
    {
        return $this->passphrase;
    }

    public function hasPassphrase(): bool
    {
        return $this->passphrase !== '';
    }

    public function requiresPassphrase(): bool
    {
        return !\in_array($this->encryption(), self::PASSPHRASELESS, true);
    }

    /** Human-readable summary of the current schedule. */
    public function describeSchedule(): string
    {
        if (!$this->scheduleEnabled()) {
            return 'Disabled';
        }

        $minute = $this->scheduleMinute();
        $hour = $this->scheduleHour();

        if (ctype_digit($minute) && ctype_digit($hour)) {
            return sprintf('Daily at %02d:%02d server time', (int) $hour, (int) $minute);
        }

        return sprintf('cron: %s %s * * *', $minute, $hour);
    }
}
