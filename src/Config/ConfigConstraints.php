<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Config;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validation rules for the configuration, as Symfony constraints.
 *
 * Several fields end up inside a command line or a cron file, so the rules are
 * about safety as much as correctness: a newline in BORG_RSH would smuggle a
 * second command past borg, and a slash in the restore directory name would
 * move user restores outside the home directory.
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
                    message: 'Repository must not include an archive name (the "::archive" part).',
                ),
                new Assert\Regex(
                    // Absolute path, ssh:// URL, or scp-style user@host:path.
                    pattern: '#^(/\S*|ssh://[^/\s]+/\S+|[^@\s/]+@[^:\s/]+:\S+)$#',
                    message: 'Repository must be an absolute path, ssh://user@host[:port]/path, or user@host:path.',
                ),
            ],
            'encryption' => [
                new Assert\Choice(
                    choices: ['repokey-blake2', 'repokey', 'keyfile-blake2', 'keyfile', 'authenticated', 'authenticated-blake2', 'none'],
                    message: 'Unknown encryption mode.',
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
            'archive_name' => [
                new Assert\NotBlank(message: 'Archive name template must not be empty.'),
                new Assert\Regex(
                    pattern: '/[\r\n\0]|::/',
                    match: false,
                    message: 'Archive name template contains invalid characters.',
                ),
            ],
            'archive_prefix' => [
                new Assert\Type('string'),
                new Assert\Regex(
                    pattern: '/[\r\n\0:]/',
                    match: false,
                    message: 'Archive prefix contains invalid characters.',
                ),
            ],
            'source_paths' => [
                new Assert\Count(min: 1, minMessage: 'At least one source path is required.'),
                new Assert\All([
                    new Assert\Regex(
                        pattern: '#^/#',
                        message: 'Source paths must be absolute.',
                    ),
                    new Assert\Regex(
                        pattern: '/[\r\n\0]/',
                        match: false,
                        message: 'Source paths must not contain control characters.',
                    ),
                ]),
            ],
            'exclude_patterns' => [
                new Assert\All([
                    new Assert\Regex(
                        pattern: '/[\r\n\0]/',
                        match: false,
                        message: 'Exclude patterns must not contain control characters.',
                    ),
                ]),
            ],
            'compression' => [
                new Assert\Regex(
                    pattern: '/^[a-z0-9]+(,[0-9]{1,2})?$/',
                    message: 'Compression must look like "lz4", "zstd,6" or "zlib,9".',
                ),
            ],
            'keep_daily'        => [new Assert\Range(min: 0, max: 9999, notInRangeMessage: 'Daily retention must be between 0 and 9999.')],
            'keep_weekly'       => [new Assert\Range(min: 0, max: 9999, notInRangeMessage: 'Weekly retention must be between 0 and 9999.')],
            'keep_monthly'      => [new Assert\Range(min: 0, max: 9999, notInRangeMessage: 'Monthly retention must be between 0 and 9999.')],
            'schedule_minute'   => [new CronField(min: 0, max: 59, label: 'Schedule minute')],
            'schedule_hour'     => [new CronField(min: 0, max: 23, label: 'Schedule hour')],
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
            'user_restore_dir' => [
                new Assert\NotBlank(message: 'Restore directory must not be empty.'),
                new Assert\Regex(
                    // A single path segment: a slash or ".." here would move
                    // user restores outside the home directory.
                    pattern: '/^(?!\.\.?$)[A-Za-z0-9._-]+$/',
                    message: 'Restore directory must be a single name using letters, digits, dot, dash or underscore.',
                ),
            ],
            'one_file_system'      => [new Assert\Type('bool')],
            'prune_enabled'        => [new Assert\Type('bool')],
            'compact_after_prune'  => [new Assert\Type('bool')],
            'schedule_enabled'     => [new Assert\Type('bool')],
            'run_after_da_backups' => [new Assert\Type('bool')],
            'restore_admin_backup' => [new Assert\Type('bool')],
            'user_restore_enabled' => [new Assert\Type('bool')],
        ];
    }
}
