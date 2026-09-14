<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Borg;

use Recranet\DirectAdminBorg\Config\Configuration;
use Recranet\DirectAdminBorg\Security\PathGuard;

/**
 * The configured borg repository, as operations rather than command lines.
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

    public function initialize(): BorgResult
    {
        return $this->borg->run(['init', '--encryption=' . $this->config->encryption(), $this->location()], 120);
    }

    public function info(): BorgResult
    {
        return $this->borg->run(['info', '--json', $this->location()], 60);
    }

    public function breakLock(): BorgResult
    {
        return $this->borg->run(['break-lock', $this->location()], 60);
    }

    public function deleteArchive(string $archive): BorgResult
    {
        return $this->borg->run(['delete', $this->archiveRef($archive)], 600);
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
     * Raw recursive listing of a subtree, used by the tests and by browsing.
     *
     * @return array{result: BorgResult, rows: array<int,array<string,mixed>>}
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

        $result = $this->borg->run($arguments, 300);

        return ['result' => $result, 'rows' => $result->jsonLines($limit)];
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
        $truncated = false;

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
            if ($relative === '' || $relative === false || str_contains($relative, '/')) {
                continue;
            }

            $children[$relative] = ArchiveEntry::fromJsonLine($row, $relative);

            if (\count($children) > $limit) {
                $truncated = true;
                break;
            }
        }

        ksort($children, SORT_NATURAL | SORT_FLAG_CASE);

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

    /** Arguments for a backup run. */
    public function createArguments(): array
    {
        $arguments = ['create', '--stats', '--json', '--compression', $this->config->compression()];

        if ($this->config->oneFileSystem()) {
            $arguments[] = '--one-file-system';
        }
        foreach ($this->config->excludePatterns() as $pattern) {
            $arguments[] = '--exclude';
            $arguments[] = $pattern;
        }

        $arguments[] = $this->archiveRef($this->config->archiveName());

        foreach ($this->config->sourcePaths() as $path) {
            $arguments[] = $path;
        }

        return $arguments;
    }

    /** Arguments for pruning, or null when pruning is switched off. */
    public function pruneArguments(): ?array
    {
        if (!$this->config->pruneEnabled()) {
            return null;
        }

        $arguments = ['prune', '--stats'];

        $prefix = $this->config->archivePrefix();
        if ($prefix !== '') {
            // Scope pruning to this plugin's own archives so it can never
            // delete archives another tool wrote to the same repository.
            $arguments[] = $this->borg->supportsGlobArchives() ? '--glob-archives' : '--prefix';
            $arguments[] = $this->borg->supportsGlobArchives() ? $prefix . '*' : $prefix;
        }

        foreach (['daily' => $this->config->keepDaily(), 'weekly' => $this->config->keepWeekly(), 'monthly' => $this->config->keepMonthly()] as $unit => $count) {
            if ($count > 0) {
                $arguments[] = sprintf('--keep-%s=%d', $unit, $count);
            }
        }

        $arguments[] = $this->location();

        return $arguments;
    }

    public function compactArguments(): array
    {
        return ['compact', $this->location()];
    }

    /**
     * Arguments for extracting archive members.
     *
     * borg strips the leading slash and writes relative to the working
     * directory, so the caller must run this with the destination as cwd.
     *
     * @param string[] $paths absolute paths as stored in the archive
     */
    public function extractArguments(string $archive, array $paths): array
    {
        $arguments = ['extract', '--list', $this->archiveRef($archive)];
        foreach ($paths as $path) {
            $arguments[] = PathGuard::toArchiveMember($path);
        }

        return $arguments;
    }

    public function checkArguments(): array
    {
        return ['check', '--repository-only', $this->location()];
    }
}
