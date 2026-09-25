<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Config;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validation rules for the configuration, as Symfony constraints.
 *
 * Two fields end up inside a command line, so the rules are about safety as
 * much as correctness: a newline in BORG_RSH would smuggle a second command
 * past borg, and a slash in the restore directory name would move user restores
 * outside the home directory.
 */
final class ConfigConstraints
{
    /** @return array<string, Constraint[]> */
    public static function rules(): array
    {
        return [
            'repository' => [
                new Assert\NotBlank(message: 'Repository must not be empty.'),
                new Assert\Regex(
                    pattern: '/[\r\n\0]/',
                    match: false,
                    message: 'Repository must be a single line.',
                ),
                new Assert\Regex(
                    pattern: '/::/',
                    match: false,
                    message: 'Repository must not include a backup name (the "::archive" part).',
                ),
                new Assert\Regex(
                    // Absolute path, ssh:// URL, or scp-style user@host:path.
                    pattern: '#^(/\S*|ssh://[^/\s]+/\S+|[^@\s/]+@[^:\s/]+:\S+)$#',
                    message: 'Repository must be an absolute path, ssh://user@host[:port]/path, or user@host:path.',
                ),
            ],
            'ssh_command' => [
                new Assert\Type('string'),
                new Assert\Regex(
                    // borg hands BORG_RSH to a shell, so a newline would let a
                    // second command in.
                    pattern: '/[\r\n\0]/',
                    match: false,
                    message: 'SSH command must be a single line.',
                ),
            ],
            'admin_backups_dir' => [
                new Assert\NotBlank(message: 'Admin backups directory must not be empty.'),
                new Assert\Regex(
                    pattern: '#^/#',
                    message: 'Admin backups directory must be an absolute path.',
                ),
                new Assert\Regex(
                    pattern: '/[\r\n\0]/',
                    match: false,
                    message: 'Admin backups directory must be a single line.',
                ),
            ],
            'archive_date_format' => [
                new Assert\Type('string'),
                new Assert\Regex(
                    pattern: '/[\r\n\0]/',
                    match: false,
                    message: 'Backup date format must be a single line.',
                ),
            ],
            'jobs_paused_from' => [
                new Assert\Regex(
                    pattern: '/^(|([01]\d|2[0-3]):[0-5]\d)$/',
                    message: 'The backup window must be given as HH:MM, such as 00:00.',
                ),
            ],
            'jobs_paused_until' => [
                new Assert\Regex(
                    pattern: '/^(|([01]\d|2[0-3]):[0-5]\d)$/',
                    message: 'The backup window must be given as HH:MM, such as 07:00.',
                ),
            ],
            'index_files'          => [new Assert\Type('bool')],
            'restore_admin_backup' => [new Assert\Type('bool')],
            'user_restore_enabled' => [new Assert\Type('bool')],
        ];
    }
}
