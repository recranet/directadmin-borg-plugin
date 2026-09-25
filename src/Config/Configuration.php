<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Config;

/**
 * Immutable view of the plugin configuration.
 *
 * This plugin restores; it does not back up. The repository is created and
 * filled by whatever already runs on the server — a cron script, a DirectAdmin
 * hook, borgmatic — so there is nothing here about source paths, compression,
 * retention or scheduling. What is left is the location to read from, the
 * credentials needed to read it, and where restores are allowed to land.
 *
 * The passphrase is not part of this object's array form: it lives in its own
 * file so the configuration can be read, logged or diffed without leaking it.
 */
final class Configuration
{
    public const DEFAULTS = [
        'repository'           => '',
        'ssh_command'          => '',
        'admin_backups_dir'    => '/home/admin/admin_backups',
        'restore_admin_backup' => true,
        'user_restore_enabled' => true,
        // Detected from the archive names rather than typed in, and remembered
        // so a repository whose names could match two timestamp shapes keeps
        // reading the same way. Empty means "work it out again".
        'archive_date_format' => '',
        // Whether an archive index records individual files as well as the
        // directory tree. Off by default: on a hosting server files outnumber
        // directories five to one, and restoring a directory brings its files
        // back regardless of whether they were ever listed.
        'index_files' => false,
        // The server's backup window, HH:MM in the server's timezone, when no
        // plugin job starts. See BackupWindow. Both empty switches it off.
        'jobs_paused_from'  => '00:00',
        'jobs_paused_until' => '07:00',
    ];

    /**
     * @param array<string,mixed> $values already validated and merged with defaults
     */
    public function __construct(
        private readonly array $values,
        private readonly string $passphrase,
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

    /** @param array<string,mixed> $values */
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

    public function sshCommand(): string
    {
        return (string) $this->get('ssh_command');
    }

    /**
     * Where DirectAdmin writes its own per-user backups.
     *
     * These hold everything that is not in the home directory — the user's
     * DirectAdmin configuration and database dumps — so a home-directory
     * restore on its own gives back the files but not the account.
     */
    public function adminBackupsDir(): string
    {
        return rtrim((string) $this->get('admin_backups_dir'), '/');
    }

    public function restoreAdminBackup(): bool
    {
        return (bool) $this->get('restore_admin_backup');
    }

    public function userRestoreEnabled(): bool
    {
        return (bool) $this->get('user_restore_enabled');
    }

    public function archiveDateFormat(): string
    {
        return (string) $this->get('archive_date_format');
    }

    public function indexFiles(): bool
    {
        return (bool) $this->get('index_files');
    }

    public function jobsPausedFrom(): string
    {
        return trim((string) $this->get('jobs_paused_from'));
    }

    public function jobsPausedUntil(): string
    {
        return trim((string) $this->get('jobs_paused_until'));
    }

    public function passphrase(): string
    {
        return $this->passphrase;
    }

    public function hasPassphrase(): bool
    {
        return $this->passphrase !== '';
    }
}
