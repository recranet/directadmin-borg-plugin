<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Job;

use Recranet\DirectAdminBorg\Paths;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Persistence for jobs: one JSON file per job plus a matching log file.
 *
 * A directory of files rather than a database, because the plugin has to work
 * on a stock DirectAdmin box with nothing else installed, and because a
 * detached worker and a web request need to see each other's writes
 * immediately.
 */
final class JobRepository
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Filesystem $filesystem,
    ) {
    }

    /** @param array<string,mixed> $params */
    public function create(string $type, string $owner, array $params = []): Job
    {
        if (!\in_array($type, Job::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown job type: ' . $type);
        }

        $job = new Job(Job::generateId($type), [
            'id'          => '',
            'type'        => $type,
            'owner'       => $owner,
            'status'      => Job::STATUS_QUEUED,
            'created_at'  => date('c'),
            'started_at'  => null,
            'finished_at' => null,
            'exit_code'   => null,
            'message'     => '',
            'params'      => $params,
            'stats'       => null,
        ]);
        $job->set(['id' => $job->id]);

        $this->persist($job);
        $this->filesystem->dumpFile($this->paths->logFile($job->id), '');
        $this->filesystem->chmod($this->paths->logFile($job->id), 0600);

        return $job;
    }

    public function find(string $id): ?Job
    {
        if (!Job::isValidId($id)) {
            return null;
        }

        $file = $this->paths->jobFile($id);
        if (!is_file($file)) {
            return null;
        }

        try {
            $data = json_decode((string) @file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) && isset($data['id']) ? new Job((string) $data['id'], $data) : null;
    }

    /**
     * Most recent jobs first.
     *
     * Job ids start with a sortable timestamp, so ordering by filename is both
     * correct and cheaper than reading every file to sort on a field.
     *
     * @return Job[]
     */
    public function recent(int $limit = 25, ?string $owner = null, ?string $type = null): array
    {
        if (!is_dir($this->paths->jobsDir())) {
            return [];
        }

        $finder = (new Finder())
            ->files()
            ->in($this->paths->jobsDir())
            ->name('*.json')
            ->depth(0)
            ->sortByName(true)
            ->reverseSorting();

        $jobs = [];
        foreach ($finder as $file) {
            $job = $this->find($file->getBasename('.json'));
            if ($job === null) {
                continue;
            }
            if ($owner !== null && $job->owner() !== $owner) {
                continue;
            }
            if ($type !== null && $job->type() !== $type) {
                continue;
            }

            $jobs[] = $job;
            if (\count($jobs) >= $limit) {
                break;
            }
        }

        return $jobs;
    }

    /** @param array<string,mixed> $changes */
    public function update(Job $job, array $changes): void
    {
        $job->set($changes);
        $this->persist($job);
    }

    public function appendLog(Job $job, string $text): void
    {
        $handle = @fopen($this->paths->logFile($job->id), 'a');
        if ($handle === false) {
            return;
        }
        @fwrite($handle, $text);
        @fclose($handle);
    }

    /** Last $lines lines of a job's log. */
    public function tail(Job $job, int $lines = 200): string
    {
        $file = $this->paths->logFile($job->id);
        if (!is_file($file)) {
            return '';
        }

        $size = (int) @filesize($file);
        $window = 64 * 1024;
        $offset = max(0, $size - $window);

        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return '';
        }
        if ($offset > 0) {
            fseek($handle, $offset);
        }
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        if ($offset > 0) {
            // Drop the partial first line produced by seeking into the middle.
            $newline = strpos($content, "\n");
            $content = $newline === false ? $content : substr($content, $newline + 1);
        }

        return implode("\n", \array_slice(explode("\n", rtrim($content, "\n")), -$lines));
    }

    /** Remove job records and logs older than $days. */
    public function purgeOlderThan(int $days = 30): int
    {
        if (!is_dir($this->paths->jobsDir())) {
            return 0;
        }

        $finder = (new Finder())
            ->files()
            ->in($this->paths->jobsDir())
            ->name('*.json')
            ->depth(0)
            ->date('< ' . $days . ' days ago');

        $removed = 0;
        foreach ($finder as $file) {
            $id = $file->getBasename('.json');
            $this->filesystem->remove([$file->getPathname(), $this->paths->logFile($id)]);
            ++$removed;
        }

        return $removed;
    }

    private function persist(Job $job): void
    {
        $this->filesystem->dumpFile(
            $this->paths->jobFile($job->id),
            json_encode($job->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n"
        );
        $this->filesystem->chmod($this->paths->jobFile($job->id), 0600);
    }
}
