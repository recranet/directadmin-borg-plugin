<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Job;

use Recranet\DirectAdminBorg\Borg\BorgResult;
use Recranet\DirectAdminBorg\Borg\BorgRunner;
use Recranet\DirectAdminBorg\Borg\Repository;
use Recranet\DirectAdminBorg\Config\Configuration;
use Recranet\DirectAdminBorg\Plugin;
use Recranet\DirectAdminBorg\Security\Account;
use Recranet\DirectAdminBorg\Security\PathGuard;

/**
 * Executes a job to completion. Runs inside the detached worker process.
 */
final class JobRunner
{
    private const LOCK_KEY = 'borg-repository';

    public function __construct(
        private readonly Plugin $plugin,
        private readonly JobRepository $jobs
    ) {
    }

    /** @return int process exit code */
    public function run(Job $job): int
    {
        $config = $this->plugin->config()->load();
        $repository = $this->plugin->repository();

        $this->jobs->update($job, ['status' => Job::STATUS_RUNNING, 'started_at' => date('c')]);

        $lock = null;

        try {
            if (!$repository->runner()->isInstalled()) {
                return $this->finish($job, Job::STATUS_FAILED, 127, sprintf(
                    'borg is not installed or not executable (%s).',
                    $repository->runner()->binary()
                ));
            }
            if (!$config->isConfigured()) {
                return $this->finish($job, Job::STATUS_FAILED, 2, 'No repository is configured.');
            }

            // Restores only read, so they may run alongside a backup; every
            // other type writes and must hold the repository lock.
            if (\in_array($job->type(), Job::EXCLUSIVE_TYPES, true)) {
                $lock = $this->plugin->lockFactory()->createLock(self::LOCK_KEY, 86400.0, false);
                if (!$lock->acquire()) {
                    return $this->finish($job, Job::STATUS_FAILED, 75, 'Another repository operation is already running.');
                }
            }

            $this->log($job, sprintf(
                'Starting %s job using borg %s.',
                $job->type(),
                $repository->runner()->version() ?? 'unknown'
            ));

            return match ($job->type()) {
                Job::TYPE_BACKUP  => $this->runBackup($job, $config, $repository),
                Job::TYPE_PRUNE   => $this->runPrune($job, $config, $repository),
                Job::TYPE_CHECK   => $this->runCheck($job, $repository),
                Job::TYPE_RESTORE => $this->runRestore($job, $config, $repository),
                default           => $this->finish($job, Job::STATUS_FAILED, 2, 'Unknown job type: ' . $job->type()),
            };
        } catch (\Throwable $e) {
            $this->log($job, 'FATAL: ' . $e->getMessage());

            return $this->finish($job, Job::STATUS_FAILED, 1, $e->getMessage());
        } finally {
            $lock?->release();
            $this->jobs->purgeOlderThan(30);
        }
    }

    private function runBackup(Job $job, Configuration $config, Repository $repository): int
    {
        $this->log($job, sprintf('Archiving %d source path(s).', \count($config->sourcePaths())));

        $result = $repository->runner()->run(
            $repository->createArguments(),
            BorgRunner::NO_TIMEOUT,
            $this->sink($job)
        );

        $stats = $this->extractStats($result);

        if (!$result->isSuccessful()) {
            $message = $result->errorMessage();

            // A template without enough resolution (say {now:%Y-%m-%d}) collides
            // as soon as a second backup runs the same day, and borg's own
            // "Archive X already exists" gives no hint about where to fix it.
            if (stripos($message, 'already exists') !== false) {
                $message .= sprintf(
                    ' The archive name template ("%s") does not produce a unique name for this run.'
                    . ' Add more precision, for example {now:%%Y-%%m-%%d_%%H:%%M:%%S}.',
                    $config->archiveName()
                );
            }

            return $this->finish($job, Job::STATUS_FAILED, $result->exitCode, 'Backup failed: ' . $message, $stats);
        }

        $status = $result->isWarning() ? Job::STATUS_WARNING : Job::STATUS_SUCCESS;
        $message = $result->isWarning()
            ? 'Archive created, but borg reported warnings (see log).'
            : 'Archive created.';

        // Prune inside the same job so it happens under the same repository
        // lock, and so an unattended schedule cannot let the repository grow
        // without bound.
        if (!empty($job->params()['prune'])) {
            $pruneArguments = $repository->pruneArguments();

            if ($pruneArguments !== null) {
                $this->log($job, 'Pruning old archives.');
                $prune = $repository->runner()->run($pruneArguments, BorgRunner::NO_TIMEOUT, $this->sink($job));

                if (!$prune->isSuccessful()) {
                    return $this->finish(
                        $job,
                        Job::STATUS_WARNING,
                        $prune->exitCode,
                        $message . ' Prune failed: ' . $prune->errorMessage(),
                        $stats
                    );
                }

                $message .= ' Old archives pruned.';

                if ($config->compactAfterPrune() && $repository->runner()->supportsCompact()) {
                    $this->log($job, 'Compacting the repository to reclaim space.');
                    $compact = $repository->runner()->run($repository->compactArguments(), BorgRunner::NO_TIMEOUT, $this->sink($job));

                    if (!$compact->isSuccessful()) {
                        $message .= ' Compact failed (see log).';
                        $status = Job::STATUS_WARNING;
                    } else {
                        $message .= ' Repository compacted.';
                    }
                }
            }
        }

        return $this->finish($job, $status, $result->exitCode, $message, $stats);
    }

    private function runPrune(Job $job, Configuration $config, Repository $repository): int
    {
        $arguments = $repository->pruneArguments();
        if ($arguments === null) {
            return $this->finish($job, Job::STATUS_FAILED, 2, 'Pruning is disabled in the plugin settings.');
        }

        $this->log($job, 'Pruning old archives.');
        $result = $repository->runner()->run($arguments, BorgRunner::NO_TIMEOUT, $this->sink($job));

        if (!$result->isSuccessful()) {
            return $this->finish($job, Job::STATUS_FAILED, $result->exitCode, 'Prune failed: ' . $result->errorMessage());
        }

        $message = 'Old archives pruned.';

        if ($config->compactAfterPrune() && $repository->runner()->supportsCompact()) {
            $this->log($job, 'Compacting the repository to reclaim space.');
            $compact = $repository->runner()->run($repository->compactArguments(), BorgRunner::NO_TIMEOUT, $this->sink($job));

            if (!$compact->isSuccessful()) {
                return $this->finish($job, Job::STATUS_WARNING, $compact->exitCode, $message . ' Compact failed: ' . $compact->errorMessage());
            }
            $message .= ' Repository compacted.';
        }

        return $this->finish($job, Job::STATUS_SUCCESS, $result->exitCode, $message);
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
        $filesystem->mkdir($destination, 0750);

        if (!is_dir($destination)) {
            return $this->finish($job, Job::STATUS_FAILED, 1, 'Unable to create the destination directory: ' . $destination);
        }

        $this->log($job, sprintf('Extracting %d path(s) from %s into %s.', \count($paths), $archive, $destination));

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

    private function extractStats(BorgResult $result): ?array
    {
        $decoded = $result->json();
        if (!isset($decoded['archive']) || !\is_array($decoded['archive'])) {
            return null;
        }

        $archive = $decoded['archive'];
        $stats = \is_array($archive['stats'] ?? null) ? $archive['stats'] : [];

        return [
            'archive'           => $archive['name'] ?? null,
            'duration'          => $archive['duration'] ?? null,
            'original_size'     => $stats['original_size'] ?? null,
            'compressed_size'   => $stats['compressed_size'] ?? null,
            'deduplicated_size' => $stats['deduplicated_size'] ?? null,
            'nfiles'            => $stats['nfiles'] ?? null,
        ];
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
        $this->jobs->appendLog($job, sprintf("[%s] %s\n", date('Y-m-d H:i:s'), rtrim($line, "\n")));
    }

    private function finish(Job $job, string $status, int $exitCode, string $message, ?array $stats = null): int
    {
        $this->log($job, $message);

        $this->jobs->update($job, [
            'status'      => $status,
            'exit_code'   => $exitCode,
            'finished_at' => date('c'),
            'message'     => $message,
            'stats'       => $stats,
        ]);

        return $status === Job::STATUS_FAILED ? 1 : 0;
    }
}
