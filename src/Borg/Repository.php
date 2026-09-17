<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Borg;

use Recranet\DirectAdminBorg\Config\Configuration;
use Recranet\DirectAdminBorg\Security\PathGuard;

/**
 * The configured borg repository, as operations rather than command lines.
 *
 * Read-only by design. The repository belongs to whatever already backs this
 * server up, so nothing here creates, writes to, prunes or deletes from it:
 * the only mutating calls left are break-lock, which releases a lock this
 * plugin or a crashed run left behind, and extract, which writes outside the
 * repository.
 */
final class Repository
{
    private BorgRunner $borg;

    public function __construct(BorgRunner $borg, private readonly Configuration $config)
    {
        // Secrets are applied through the environment, never as arguments, so
        // they cannot appear in ps output or in a logged command line.
        $this->borg = $borg->withEnvironment([
            'BORG_PASSPHRASE' => $config->passphrase(),
            'BORG_RSH'        => $config->sshCommand(),
        ]);
    }

    public function runner(): BorgRunner
    {
        return $this->borg;
    }

    public function location(): string
    {
        return $this->config->repository();
    }

    public function archiveRef(string $archive): string
    {
        return $this->location() . '::' . $archive;
    }

    public function info(): BorgResult
    {
        return $this->borg->run(['info', '--json', $this->location()], 60);
    }

    public function breakLock(): BorgResult
    {
        return $this->borg->run(['break-lock', $this->location()], 60);
    }

    /**
     * Archives, newest first (borg returns them oldest first).
     *
     * @return array{result: BorgResult, archives: Archive[]}
     */
    public function listArchives(): array
    {
        $result = $this->borg->run(['list', '--json', $this->location()], 120);

        $archives = [];
        if ($result->isSuccessful()) {
            foreach ($result->json()['archives'] ?? [] as $row) {
                if (\is_array($row)) {
                    $archives[] = Archive::fromArray($row);
                }
            }
        }
        usort($archives, static fn (Archive $a, Archive $b) => strcmp($b->time, $a->time));

        return ['result' => $result, 'archives' => $archives];
    }

    /**
     * Raw recursive listing of a subtree.
     *
     * Streamed and capped rather than buffered. borg has no depth limit, so a
     * listing without a path is the entire archive: 2.5 million entries and
     * ~880 MB of JSON on a real hosting server, which is enough to kill the
     * process outright. The cap is applied as the lines arrive, and borg is
     * hung up on the moment it is reached, so neither memory nor time depends
     * on how big the archive is.
     *
     * This is the fallback for a bounded subtree. Browsing goes through
     * ArchiveIndex, because even a capped scan still costs a full pass over the
     * archive metadata.
     *
     * @return array{result: BorgResult, rows: array<int,array<string,mixed>>, truncated: bool}
     */
    public function listSubtree(string $archive, ?string $path = null, int $limit = 50000): array
    {
        $arguments = ['list', '--json-lines', $this->archiveRef($archive)];

        if ($path !== null && $path !== '' && $path !== '/') {
            // A positional path restricts the listing to that subtree. Not
            // --pattern: that expects an include/exclude command such as
            // "+pp:...", and a bare "pp:..." makes borg 1.2 print a traceback.
            $arguments[] = PathGuard::toArchiveMember($path);
        }

        $rows = [];
        $truncated = false;

        $result = $this->borg->runStreaming(
            $arguments,
            static function (string $line) use (&$rows, &$truncated, $limit): bool {
                $decoded = json_decode($line, true);
                if (!\is_array($decoded)) {
                    return true;
                }

                $rows[] = $decoded;

                if (\count($rows) >= $limit) {
                    $truncated = true;

                    return false;
                }

                return true;
            },
            600
        );

        return ['result' => $result, 'rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * One level of the tree, for the browse UI.
     *
     * borg has no single-level listing, so this asks for the subtree — letting
     * borg do the filtering — and reduces it to direct children here. Oversized
     * directories are capped and reported rather than stalling the page.
     */
    public function listDirectory(string $archive, string $path, int $limit = 2000): DirectoryListing
    {
        $directory = rtrim(PathGuard::normalize($path), '/');
        $prefix = $directory === '' ? '' : ltrim($directory, '/') . '/';

        $listing = $this->listSubtree($archive, $directory === '' ? null : $directory, $limit * 25);

        $children = [];
        $truncated = $listing['truncated'];

        foreach ($listing['rows'] as $row) {
            $entryPath = '/' . ltrim((string) ($row['path'] ?? ''), '/');

            if ($prefix !== '' && !str_starts_with($entryPath, '/' . $prefix)) {
                // Defensive: only slice paths that really are under $directory.
                continue;
            }

            $relative = $prefix === ''
                ? ltrim($entryPath, '/')
                : substr($entryPath, \strlen($prefix) + 1);

            // '' is the directory itself; anything with a slash is deeper.
            if ($relative === '' || str_contains($relative, '/')) {
                continue;
            }

            $children[$relative] = ArchiveEntry::fromJsonLine($row, $relative);

            if (\count($children) > $limit) {
                $truncated = true;
                break;
            }
        }

        ksort($children, \SORT_NATURAL | \SORT_FLAG_CASE);

        // Directories first, then files, each already sorted by name.
        $directories = [];
        $files = [];
        foreach ($children as $entry) {
            if ($entry->isDirectory()) {
                $directories[] = $entry;
            } else {
                $files[] = $entry;
            }
        }

        return new DirectoryListing(
            array_merge($directories, $files),
            $truncated,
            $listing['result']->isSuccessful() || $children !== [],
            $listing['result']->isSuccessful() ? '' : $listing['result']->errorMessage(),
        );
    }

    /**
     * Extensions DirectAdmin may have written an admin backup with, in the
     * order they are preferred when more than one is present.
     */
    public const ADMIN_BACKUP_EXTENSIONS = ['tar.zst', 'tar.gz', 'tar.bz2', 'tar'];

    /**
     * Whether a file in the backups directory is this account's backup.
     *
     * DirectAdmin writes these as <level>.<creator>.<user>.tar.<ext> --
     * `user.admin.beaujean.tar.zst`, or `admin.root.admin.tar.zst` for an admin
     * account -- while some setups produce the plain `<user>.tar.<ext>`. Both
     * are accepted.
     *
     * The dot before the username is what makes the suffix match safe: looking
     * for ".jean.tar.zst" does not match "user.admin.beaujean.tar.zst", where a
     * bare "jean.tar.zst" would, and restoring the wrong customer's databases
     * is not a mistake worth risking to save a character.
     */
    private static function isAdminBackupFor(string $filename, string $username, string $extension): bool
    {
        $suffix = $username . '.' . $extension;

        return $filename === $suffix || str_ends_with($filename, '.' . $suffix);
    }

    /**
     * Find a user's DirectAdmin admin backup inside an archive.
     *
     * DirectAdmin names these <username>.<ext>, and the extension depends on
     * the compression configured when the backup ran, so the archive is asked
     * rather than guessed at.
     */
    public function findAdminBackup(string $archive, string $adminBackupsDir, string $username): ?ArchiveEntry
    {
        return self::pickAdminBackup($this->listDirectory($archive, $adminBackupsDir)->entries, $username);
    }

    /**
     * Pick a user's DirectAdmin backup out of an already-listed directory.
     *
     * Separate from findAdminBackup() so the archive index can answer the same
     * question without going back to borg, which costs a full archive scan.
     *
     * @param ArchiveEntry[] $entries
     */
    public static function pickAdminBackup(array $entries, string $username): ?ArchiveEntry
    {
        $candidates = [];
        foreach ($entries as $entry) {
            if (!$entry->isDirectory()) {
                $candidates[$entry->name] = $entry;
            }
        }

        // Extension order is preference order, so an exact name is only
        // preferred over a prefixed one within the same extension.
        foreach (self::ADMIN_BACKUP_EXTENSIONS as $extension) {
            foreach ($candidates as $name => $entry) {
                if (self::isAdminBackupFor((string) $name, $username, $extension)) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * Arguments for extracting archive members.
     *
     * borg strips the leading slash and writes relative to the working
     * directory, so the caller must run this with the destination as cwd.
     *
     * @param string[] $paths absolute paths as stored in the archive
     *
     * @return string[]
     */
    public function extractArguments(string $archive, array $paths): array
    {
        $arguments = ['extract', '--list', $this->archiveRef($archive)];
        foreach ($paths as $path) {
            $arguments[] = PathGuard::toArchiveMember($path);
        }

        return $arguments;
    }

    /** @return string[] */
    public function checkArguments(): array
    {
        return ['check', '--repository-only', $this->location()];
    }
}
