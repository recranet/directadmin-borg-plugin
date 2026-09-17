<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Job;

use Recranet\DirectAdminBorg\Borg\BorgRunner;
use Recranet\DirectAdminBorg\Borg\Repository;
use Recranet\DirectAdminBorg\Config\Configuration;
use Recranet\DirectAdminBorg\Plugin;
use Recranet\DirectAdminBorg\Security\Account;
use Recranet\DirectAdminBorg\Security\PathGuard;
use Symfony\Component\Lock\LockInterface;

/**
 * Executes a job to completion. Runs inside the detached worker process.
 */
final class JobRunner
{
    private const LOCK_KEY = 'borg-repository';

    /** Held for the duration of a job that writes to the repository. */
    private ?LockInterface $lock = null;

    public function __construct(
        private readonly Plugin $plugin,
        private readonly JobRepository $jobs,
    ) {
    }

    /** @return int process exit code */
    public function run(Job $job): int
    {
        $config = $this->plugin->config()->load();
        $repository = $this->plugin->repository();

        $this->jobs->update($job, ['status' => Job::STATUS_RUNNING, 'started_at' => date('c')]);

        try {
            if (!$repository->runner()->isInstalled()) {
                return $this->finish($job, Job::STATUS_FAILED, 127, \sprintf(
                    'borg is not installed or not executable (%s).',
                    $repository->runner()->binary()
                ));
            }
            if (!$config->isConfigured()) {
                return $this->finish($job, Job::STATUS_FAILED, 2, 'No repository is configured.');
            }

            // A restore may always run: it must stay possible while a check,
            // or the server's own backup cron, has the repository busy.
            if (\in_array($job->type(), Job::EXCLUSIVE_TYPES, true)) {
                $this->lock = $this->plugin->lockFactory()->createLock(self::LOCK_KEY, 86400.0, false);
                if (!$this->lock->acquire()) {
                    $this->lock = null;

                    return $this->finish($job, Job::STATUS_FAILED, 75, 'Another repository operation is already running.');
                }
            }

            $this->log($job, \sprintf(
                'Starting %s job using borg %s.',
                $job->type(),
                $repository->runner()->version() ?? 'unknown'
            ));

            return match ($job->type()) {
                Job::TYPE_CHECK   => $this->runCheck($job, $repository),
                Job::TYPE_RESTORE => $this->runRestore($job, $config, $repository),
                Job::TYPE_INDEX   => $this->runIndex($job, $repository),
                default           => $this->finish($job, Job::STATUS_FAILED, 2, 'Unknown job type: ' . $job->type()),
            };
        } catch (\Throwable $e) {
            $this->log($job, 'FATAL: ' . $e->getMessage());

            return $this->finish($job, Job::STATUS_FAILED, 1, $e->getMessage());
        } finally {
            // Normally already released by finish(); this is the path where an
            // exception escaped before it ran.
            $this->releaseLock();
            $this->jobs->purgeOlderThan(30);
        }
    }

    private function runCheck(Job $job, Repository $repository): int
    {
        $this->log($job, 'Running a repository consistency check.');
        $result = $repository->runner()->run($repository->checkArguments(), BorgRunner::NO_TIMEOUT, $this->sink($job));

        return $this->finish(
            $job,
            $result->isSuccessful() ? Job::STATUS_SUCCESS : Job::STATUS_FAILED,
            $result->exitCode,
            $result->isSuccessful() ? 'Repository check completed.' : $result->errorMessage()
        );
    }

    /**
     * Build the browsable index for one archive.
     *
     * Slow by nature -- it is one pass over every entry in the archive -- which
     * is exactly why it is a job: the work happens once, in the background,
     * with progress in the log, instead of inside a page request that would
     * time out or run out of memory.
     */
    private function runIndex(Job $job, Repository $repository): int
    {
        $archive = (string) ($job->params()['archive'] ?? '');
        if ($archive === '') {
            return $this->finish($job, Job::STATUS_FAILED, 2, 'Index job is missing an archive name.');
        }

        // Its own lock, not the repository one: indexing only reads, so it must
        // not block a restore. What it must block is a second index of the same
        // archive, which would have two processes writing one file.
        $lock = $this->plugin->lockFactory()->createLock('borg-index-' . sha1($archive), 86400.0, false);
        if (!$lock->acquire()) {
            return $this->finish($job, Job::STATUS_FAILED, 75, 'This archive is already being indexed.');
        }

        $includeFiles = (bool) ($job->params()['files'] ?? false);

        $this->log($job, \sprintf(
            'Indexing %s (%s). This reads every entry in the archive once.',
            $archive,
            $includeFiles ? 'directories and files' : 'directory tree only'
        ));

        $index = $this->plugin->archiveIndex();

        try {
            $built = $index->build(
                $repository,
                $archive,
                function (int $entries) use ($job): void {
                    $this->log($job, \sprintf('  %s entries indexed…', number_format($entries)));
                },
                $includeFiles,
                $this->plugin->config()->load()->adminBackupsDir()
            );
        } finally {
            $lock->release();
        }

        if (!$built['result']->isSuccessful()) {
            return $this->finish(
                $job,
                Job::STATUS_FAILED,
                $built['result']->exitCode,
                'Could not read the archive: ' . $built['result']->errorMessage()
            );
        }

        return $this->finish($job, Job::STATUS_SUCCESS, 0, \sprintf(
            'Indexed %s %s. Browsing this archive is now immediate.',
            number_format($built['entries']),
            $includeFiles ? 'entries' : 'directories and links'
        ));
    }

    private function runRestore(Job $job, Configuration $config, Repository $repository): int
    {
        $params = $job->params();

        $archive = (string) ($params['archive'] ?? '');
        $paths = array_values(array_filter(array_map('strval', (array) ($params['paths'] ?? []))));
        $destination = (string) ($params['destination'] ?? '');
        $owner = (string) ($params['chown_to'] ?? '');

        if ($archive === '' || $paths === [] || $destination === '') {
            return $this->finish($job, Job::STATUS_FAILED, 2, 'Restore job is missing an archive, paths or destination.');
        }

        // Re-validate rather than trusting the job file. The worker runs as
        // root and the job file is its only input, so the confinement check
        // that the UI already made is repeated here at the point of use.
        $account = null;
        if ($owner !== '') {
            $account = Account::resolve($owner, $this->plugin->paths);
            $destination = $account->confine($destination);
            foreach ($paths as $path) {
                $account->confine($path);
            }
        } else {
            $destination = PathGuard::normalize($destination);
        }

        $filesystem = $this->plugin->filesystem();

        // Optional pre-clean. A restore is an overlay: borg cannot delete during
        // an extract, so anything the archive does not contain survives it. That
        // is wrong for malware cleanup, where the whole point is that files the
        // attacker added must not come back.
        $cleanTargets = (array) ($params['clean_paths'] ?? []);

        // The root every deletion must sit inside. A user-level restore carries
        // the account it belongs to; an admin restore-a-user names the home
        // explicitly, because it deliberately does not set chown_to (borg
        // restores the archived ownership instead).
        $cleanRoot = $account !== null
            ? $account->home
            : trim((string) ($params['clean_root'] ?? ''));

        if ($cleanTargets !== [] && $cleanRoot === '') {
            return $this->finish($job, Job::STATUS_FAILED, 2, 'Restore job asks to delete files but names no root to confine that to.');
        }

        foreach ($cleanTargets as $cleanPath) {
            // Re-validated here, not trusted from the job file: this deletes
            // recursively as root.
            $cleanPath = $this->assertCleanable((string) $cleanPath, PathGuard::normalize($cleanRoot));

            if (!file_exists($cleanPath) && !is_link($cleanPath)) {
                $this->log($job, 'Nothing to remove at ' . $cleanPath . '.');
                continue;
            }

            $this->log($job, 'Removing ' . $cleanPath . ' before restoring.');
            $filesystem->remove($cleanPath);

            if (file_exists($cleanPath) || is_link($cleanPath)) {
                return $this->finish($job, Job::STATUS_FAILED, 1, 'Could not remove ' . $cleanPath . ' before restoring.');
            }
        }

        $filesystem->mkdir($destination, 0750);

        if (!is_dir($destination)) {
            return $this->finish($job, Job::STATUS_FAILED, 1, 'Unable to create the destination directory: ' . $destination);
        }

        $this->log($job, \sprintf('Extracting %d path(s) from %s into %s.', \count($paths), $archive, $destination));

        $result = $repository->runner()->run(
            $repository->extractArguments($archive, $paths),
            BorgRunner::NO_TIMEOUT,
            $this->sink($job),
            $destination,
        );

        if (!$result->isSuccessful()) {
            return $this->finish($job, Job::STATUS_FAILED, $result->exitCode, 'Restore failed: ' . $result->errorMessage());
        }

        if ($account !== null) {
            // Extraction ran as root, so hand the files back before the user
            // ever sees them.
            $this->log($job, 'Restoring ownership to ' . $account->username . '.');
            $account->takeOwnership($destination, $filesystem);
        }

        return $this->finish(
            $job,
            $result->isWarning() ? Job::STATUS_WARNING : Job::STATUS_SUCCESS,
            $result->exitCode,
            'Restored into ' . $destination
        );
    }

    /**
     * Assert a path may be deleted: inside $root, and never $root itself.
     *
     * Removing the home directory outright would take mail, cron, SSH keys and
     * anything else that is not part of a website with it, so a pre-clean is
     * always a subdirectory.
     *
     * @throws \Recranet\DirectAdminBorg\Exception\UnsafePathException
     */
    private function assertCleanable(string $path, string $root): string
    {
        if ($root === '' || $root === '/') {
            throw new \Recranet\DirectAdminBorg\Exception\UnsafePathException('No safe root for a pre-clean.');
        }

        $path = PathGuard::confine($path, $root);

        if ($path === rtrim($root, '/')) {
            throw new \Recranet\DirectAdminBorg\Exception\UnsafePathException('Refusing to delete ' . $root . ' itself; choose a subdirectory.');
        }

        return $path;
    }

    /** Streams borg's output into the job log so the UI can tail it live. */
    private function sink(Job $job): callable
    {
        return function (string $type, string $chunk) use ($job): void {
            $this->jobs->appendLog($job, $chunk);
        };
    }

    private function log(Job $job, string $line): void
    {
        $this->jobs->appendLog($job, \sprintf("[%s] %s\n", date('Y-m-d H:i:s'), rtrim($line, "\n")));
    }

    private function releaseLock(): void
    {
        $this->lock?->release();
        $this->lock = null;
    }

    private function finish(Job $job, string $status, int $exitCode, string $message): int
    {
        $this->log($job, $message);

        // Release before the job is marked finished, not after. Otherwise there
        // is a window where the UI shows a completed check while the lock is
        // still held, and the next one is refused as "already running".
        $this->releaseLock();

        $this->jobs->update($job, [
            'status'      => $status,
            'exit_code'   => $exitCode,
            'finished_at' => date('c'),
            'message'     => $message,
        ]);

        return $status === Job::STATUS_FAILED ? 1 : 0;
    }
}
