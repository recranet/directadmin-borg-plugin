<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Borg;

use Recranet\DirectAdminBorg\Paths;
use Recranet\DirectAdminBorg\Security\PathGuard;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * A browsable index of one archive's paths.
 *
 * borg 1.x has no way to list a single directory. `borg list` walks the whole
 * item metadata stream whichever way you ask, so every directory click costs a
 * full scan: seventeen seconds on the archive this was written against, which
 * holds 2.5 million entries. Asking for the root without a path is worse — borg
 * then prints all 2.5 million, and buffering that killed the page outright.
 *
 * So the scan happens once, in the background, and the result is written to
 * disk in a form a directory listing can be answered from immediately.
 *
 * The format is deliberately dull: one tab-separated line per entry, sorted
 * bytewise, with the parent directory as the first field. Every child of a
 * directory is therefore contiguous, and a binary search over byte offsets
 * finds the first of them without reading the file. No database, because plugin
 * scripts run on `php -n` and neither pdo_sqlite nor anything else is
 * guaranteed to be there; the only external tool is sort(1), which is in
 * coreutils.
 *
 * Archives are immutable in borg, so an index never needs invalidating. It only
 * needs removing when its archive is pruned away, which purge() does.
 *
 * By default only the directory tree is recorded. On the server this was
 * written against that is 408,851 entries out of 2,527,934 -- files outnumber
 * directories five to one -- so skipping them makes the index a fraction of the
 * size for no loss in what can be recovered: restoring a directory extracts
 * everything under it whether or not the index ever listed it. What it costs is
 * the ability to tick one file out of a directory, which is what index_files
 * turns back on. Symlinks are always recorded; there are few of them and one of
 * them is usually public_html pointing somewhere unexpected.
 */
final class ArchiveIndex
{
    /** Fields in each index line, in order. */
    private const FIELDS = ['parent', 'name', 'type', 'size', 'mode', 'owner', 'mtime'];

    /** How often build() reports progress, in entries. */
    private const PROGRESS_EVERY = 100000;

    public function __construct(
        private readonly Paths $paths,
        private readonly Filesystem $filesystem,
        private readonly string $repository,
    ) {
    }

    /**
     * Where an archive's index lives.
     *
     * Keyed by repository as well as archive: pointing the plugin at a
     * different repository must not surface a stale index from the old one,
     * and archive names are not unique across repositories.
     */
    public function file(string $archive): string
    {
        return $this->paths->indexDir() . '/' . hash('sha256', $this->repository . "\0" . $archive) . '.idx';
    }

    public function exists(string $archive): bool
    {
        return is_file($this->file($archive));
    }

    /** Entries in a built index, or null when it has not been built. */
    public function count(string $archive): ?int
    {
        $entries = $this->meta($archive)['entries'] ?? null;

        return $entries === null ? null : (int) $entries;
    }

    /** Whether this archive's index lists individual files, or only the tree. */
    public function includesFiles(string $archive): bool
    {
        return (bool) ($this->meta($archive)['files'] ?? false);
    }

    /** When this archive's index was built, or null if it has not been. */
    public function builtAt(string $archive): ?string
    {
        $built = $this->meta($archive)['built_at'] ?? null;

        return \is_string($built) ? $built : null;
    }

    /**
     * Whether an index was built under settings that no longer apply.
     *
     * An index is a snapshot of a decision as much as of an archive: which
     * directory DirectAdmin's backups were expected in, and whether files were
     * wanted. Change either and what is on disk silently stops answering the
     * question being asked of it -- the restore screen would report that an
     * account has no DirectAdmin backup when it has one, just somewhere the
     * index was never told to look. Better to say so and offer to rebuild.
     */
    public function isStale(string $archive, string $adminBackupsDir, bool $wantFiles): bool
    {
        if (!$this->exists($archive)) {
            return false;
        }

        $meta = $this->meta($archive);
        $always = (string) ($meta['always'] ?? '');

        if (rtrim($always, '/') !== rtrim($adminBackupsDir, '/')) {
            return true;
        }

        // Having more than was asked for is not stale; having less is.
        return $wantFiles && !(bool) ($meta['files'] ?? false);
    }

    /** @return array<string,mixed> */
    private function meta(string $archive): array
    {
        $raw = @file_get_contents($this->file($archive) . '.meta');
        if (!\is_string($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Scan the archive once and write its index.
     *
     * Memory stays flat regardless of archive size: borg's output is consumed a
     * line at a time and appended straight to a file, and the sort that follows
     * is sort(1)'s problem, which spills to temporary files rather than RAM.
     *
     * @param callable|null $onProgress   fn(int $entries): void
     * @param bool          $includeFiles record regular files too, not just the tree
     *
     * @return array{result: BorgResult, entries: int}
     */
    public function build(
        Repository $repository,
        string $archive,
        ?callable $onProgress = null,
        bool $includeFiles = false,
        string $alwaysInclude = '',
    ): array {
        // One directory's files are always recorded, however the flag is set:
        // DirectAdmin's per-user backups. There is one small file per account
        // in there, and whether a given account has one decides what the
        // restore screen can offer -- a question that would otherwise cost a
        // full archive scan every time the screen is opened.
        $always = $alwaysInclude === '' ? '' : rtrim($alwaysInclude, '/') . '/';
        $this->filesystem->mkdir($this->paths->indexDir(), 0700);

        $target = $this->file($archive);
        $unsorted = $target . '.building';

        $handle = fopen($unsorted, 'w');
        if ($handle === false) {
            throw new \RuntimeException('Could not open the index for writing: ' . $unsorted);
        }

        $entries = 0;

        try {
            $result = $repository->runner()->runStreaming(
                ['list', '--json-lines', $repository->archiveRef($archive)],
                function (string $line) use ($handle, &$entries, $onProgress, $includeFiles, $always): bool {
                    $row = json_decode($line, true);
                    if (!\is_array($row)) {
                        return true;
                    }

                    // Directories and symlinks always; regular files only when
                    // asked for, or when they sit under $alwaysInclude. The
                    // decision is made here, before anything is written, so a
                    // skipped entry costs nothing but the read.
                    if (!$includeFiles && !\in_array($row['type'] ?? '-', ['d', 'l'], true)) {
                        $path = '/' . ltrim((string) ($row['path'] ?? ''), '/');
                        if ($always === '' || !str_starts_with($path, $always)) {
                            return true;
                        }
                    }

                    $written = $this->line($row);
                    if ($written !== null) {
                        fwrite($handle, $written);
                        ++$entries;

                        if ($onProgress !== null && $entries % self::PROGRESS_EVERY === 0) {
                            $onProgress($entries);
                        }
                    }

                    return true;
                },
                BorgRunner::NO_TIMEOUT
            );
        } finally {
            fclose($handle);
        }

        if (!$result->isSuccessful()) {
            $this->filesystem->remove($unsorted);

            return ['result' => $result, 'entries' => 0];
        }

        $this->sortInto($unsorted, $target);
        $this->filesystem->remove($unsorted);

        $this->filesystem->dumpFile(
            $target . '.meta',
            json_encode([
                'archive'    => $archive,
                'repository' => $this->repository,
                'entries'    => $entries,
                'files'      => $includeFiles,
                'always'     => $alwaysInclude,
                'built_at'   => date('c'),
            ], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)
        );
        $this->filesystem->chmod($target, 0600);
        $this->filesystem->chmod($target . '.meta', 0600);

        return ['result' => $result, 'entries' => $entries];
    }

    /** One level of the tree, straight out of the index. */
    public function listDirectory(string $archive, string $path, int $limit = 2000): DirectoryListing
    {
        $file = $this->file($archive);
        if (!is_file($file)) {
            return new DirectoryListing([], false, false, 'This archive has not been indexed yet.');
        }

        $directory = rtrim(PathGuard::normalize($path), '/');
        $target = $directory === '' ? '/' : $directory;

        $handle = fopen($file, 'r');
        if ($handle === false) {
            return new DirectoryListing([], false, false, 'The index could not be read.');
        }

        try {
            $size = (int) filesize($file);
            $this->seekToParent($handle, $this->encode($target), $size);

            $directories = [];
            $files = [];
            $truncated = false;

            while (($line = fgets($handle)) !== false) {
                $row = $this->parse(rtrim($line, "\n"));
                if ($row === null) {
                    continue;
                }
                // Sorted by parent, so the first non-match ends this directory.
                if ($row['parent'] !== $target) {
                    break;
                }

                if (\count($directories) + \count($files) >= $limit) {
                    $truncated = true;
                    break;
                }

                $entry = new ArchiveEntry(
                    ($target === '/' ? '' : $target) . '/' . $row['name'],
                    $row['name'],
                    $row['type'],
                    (int) $row['size'],
                    $row['mode'],
                    $row['owner'],
                    $row['mtime'],
                );

                if ($entry->isDirectory()) {
                    $directories[] = $entry;
                } else {
                    $files[] = $entry;
                }
            }
        } finally {
            fclose($handle);
        }

        // Already in name order within the directory, because the whole line
        // was sorted and the name is the field after the parent.
        return new DirectoryListing(array_merge($directories, $files), $truncated, true);
    }

    /** Remove indexes for archives that are no longer in the repository. */
    public function purge(string ...$keep): void
    {
        $directory = $this->paths->indexDir();
        if (!is_dir($directory)) {
            return;
        }

        $wanted = [];
        foreach ($keep as $archive) {
            $wanted[$this->file($archive)] = true;
        }

        foreach ((glob($directory . '/*.idx') ?: []) as $file) {
            if (!isset($wanted[$file])) {
                $this->filesystem->remove([$file, $file . '.meta']);
            }
        }
    }

    // ------------------------------------------------------------- internals

    /**
     * Find the first line whose parent field is >= $target.
     *
     * Bisection over byte offsets rather than lines: reading the first whole
     * line at or after an offset is monotone in that offset, which is all a
     * binary search needs. Converging on the offset means the file is touched
     * a few dozen times instead of being read.
     */
    private function seekToParent(mixed $handle, string $target, int $size): void
    {
        $low = 0;
        $high = $size;

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            $parent = $this->parentAt($handle, $middle);

            // null is end of file, which sorts after everything.
            if ($parent !== null && strcmp($parent, $target) < 0) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        fseek($handle, $low);
        if ($low > 0) {
            // $low is unlikely to be a line start; drop the partial line.
            fgets($handle);
        }
    }

    /** The parent field of the first whole line at or after $offset. */
    private function parentAt(mixed $handle, int $offset): ?string
    {
        fseek($handle, $offset);
        if ($offset > 0) {
            fgets($handle);
        }

        $line = fgets($handle);
        if ($line === false) {
            return null;
        }

        $tab = strpos($line, "\t");

        return $tab === false ? null : substr($line, 0, $tab);
    }

    /**
     * Turn a `borg list --json-lines` row into an index line.
     *
     * Every field is encoded, because a file name may legitimately contain a
     * tab or a newline and either would silently corrupt a line-based,
     * tab-separated file. This is not hypothetical on shared hosting, where the
     * files come from customers.
     *
     * @param array<string,mixed> $row
     */
    private function line(array $row): ?string
    {
        $path = '/' . ltrim((string) ($row['path'] ?? ''), '/');
        if ($path === '/') {
            return null;
        }

        $parent = \dirname($path);
        $name = basename($path);
        if ($name === '') {
            return null;
        }

        $owner = trim(((string) ($row['user'] ?? '')) . ':' . ((string) ($row['group'] ?? '')), ':');

        return implode("\t", [
            $this->encode($parent),
            $this->encode($name),
            $this->encode((string) ($row['type'] ?? '-')),
            (string) (int) ($row['size'] ?? 0),
            $this->encode((string) ($row['mode'] ?? '')),
            $this->encode($owner),
            $this->encode((string) ($row['mtime'] ?? '')),
        ]) . "\n";
    }

    /** @return array<string,string>|null */
    private function parse(string $line): ?array
    {
        $parts = explode("\t", $line);
        if (\count($parts) !== \count(self::FIELDS)) {
            return null;
        }

        $row = [];
        foreach (self::FIELDS as $position => $field) {
            $row[$field] = $this->decode($parts[$position]);
        }

        return $row;
    }

    private function encode(string $value): string
    {
        return strtr($value, ['%' => '%25', "\t" => '%09', "\n" => '%0A', "\r" => '%0D']);
    }

    private function decode(string $value): string
    {
        // strtr() with an array replaces each position once and never
        // reprocesses what it wrote, so "%2509" decodes to "%09" rather than
        // being expanded a second time into a tab.
        return strtr($value, ['%09' => "\t", '%0A' => "\n", '%0D' => "\r", '%25' => '%']);
    }

    /**
     * Sort the scratch file into place with sort(1).
     *
     * LC_ALL=C because the binary search compares bytes: a locale-aware
     * collation would order the file differently from strcmp() and the search
     * would miss. -T keeps sort's temporary files on the same filesystem as the
     * index rather than filling /tmp.
     */
    private function sortInto(string $source, string $target): void
    {
        $temporary = $this->paths->indexDir() . '/tmp';
        $this->filesystem->mkdir($temporary, 0700);

        $process = new Process(
            ['sort', '-T', $temporary, '-o', $target, $source],
            '/',
            ['LC_ALL' => 'C', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
            null,
            null
        );
        $process->setInput('');
        $process->run();

        if (!$process->isSuccessful()) {
            $this->filesystem->remove($target);

            throw new \RuntimeException('Could not sort the archive index: ' . trim($process->getErrorOutput()));
        }
    }
}
