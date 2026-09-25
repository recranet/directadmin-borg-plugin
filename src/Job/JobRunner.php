<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Job;

use Recranet\DirectAdminBorg\Borg\BorgRunner;
use Recranet\DirectAdminBorg\Borg\Repository;
use Recranet\DirectAdminBorg\Config\Configuration;
use Recranet\DirectAdminBorg\Exception\BorgPluginException;
use Recranet\DirectAdminBorg\Exception\UnsafePathException;
use Recranet\DirectAdminBorg\Plugin;
use Recranet\DirectAdminBorg\Security\Account;
use Recranet\DirectAdminBorg\Security\AccountFilesystem;
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
            return $this->finish($job, Job::STATUS_FAILED, 2, 'Index job is missing a backup name.');
        }

        // Its own lock, not the repository one: indexing only reads, so it must
        // not block a restore. What it must block is a second index of the same
        // archive, which would have two processes writing one file.
        $lock = $this->plugin->lockFactory()->createLock('borg-index-' . sha1($archive), 86400.0, false);
        if (!$lock->acquire()) {
            return $this->finish($job, Job::STATUS_FAILED, 75, 'This backup is already being indexed.');
        }

        $includeFiles = (bool) ($job->params()['files'] ?? false);

        $this->log($job, \sprintf(
            'Indexing %s (%s). This reads every entry in the backup once.',
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
                'Could not read the backup: ' . $built['result']->errorMessage()
            );
        }

        return $this->finish($job, Job::STATUS_SUCCESS, 0, \sprintf(
            'Indexed %s %s. Browsing this backup is now immediate.',
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

        if ($archive === '' || $paths === [] || $destination === '') {
            return $this->finish($job, Job::STATUS_FAILED, 2, 'Restore job is missing a backup, paths or destination.');
        }

        $inPlace = (bool) ($params['in_place'] ?? false);

        // Whose home bounds this restore. Normally the account that owns the
        // files; chown_to is the older spelling and still honoured, because it
        // is what a job queued by a previous version carries.
        $confineTo = (string) ($params['confine_to'] ?? '') ?: (string) ($params['chown_to'] ?? '');

        // Re-validate rather than trusting the job file. The worker runs as
        // root and the job file is its only input, so the confinement check
        // that the UI already made is repeated here at the point of use.
        $account = null;
        if ($confineTo !== '') {
            $account = Account::resolve($confineTo, $this->plugin->paths);
            foreach ($paths as $path) {
                $account->confine($path);
            }
        }

        if ($inPlace) {
            // borg strips the leading slash and writes each member back to its
            // own absolute path, so "/" is what "in place" means. It is
            // deliberately not confined to the home: the paths are, and they
            // are what decide where anything lands.
            $destination = '/';
        } elseif ($account !== null) {
            $destination = $account->confine($destination);
        } else {
            $destination = PathGuard::normalize($destination);
        }

        // Resolved before a byte is written: a delivery that names a directory
        // this job may not write to should fail now, not after ten minutes of
        // extracting.
        $delivery = $this->resolveDelivery($params, $paths, $inPlace);
        if ($delivery !== null) {
            if ((array) ($params['clean_paths'] ?? []) !== []) {
                return $this->finish($job, Job::STATUS_FAILED, 2, 'A delivered restore copies one file and deletes nothing.');
            }

            return $this->runDelivery($job, $repository, $archive, $paths[0], $delivery);
        }

        // Whose home this restore writes into, and so who does the writing. A
        // user-level job names the account in confine_to; an admin
        // restore-a-user names it in restore_user. Resolved lazily: restoring
        // only a deleted account's DirectAdmin tarball names a user who no
        // longer has a home, and needs none.
        $homeAccount = $account;
        $homeAccountName = $confineTo ?: trim((string) ($params['restore_user'] ?? ''));
        $resolveHomeAccount = function () use (&$homeAccount, $homeAccountName): Account {
            if ($homeAccount === null) {
                if ($homeAccountName === '') {
                    throw new UnsafePathException('A restore into a home directory must name the account it belongs to.');
                }
                $homeAccount = Account::resolve($homeAccountName, $this->plugin->paths);
            }

            return $homeAccount;
        };

        // Optional pre-clean. A restore is an overlay: borg cannot delete during
        // an extract, so anything the archive does not contain survives it. That
        // is wrong for malware cleanup, where the whole point is that files the
        // attacker added must not come back.
        $cleanTargets = (array) ($params['clean_paths'] ?? []);

        if ($cleanTargets !== [] && $homeAccountName === '') {
            return $this->finish($job, Job::STATUS_FAILED, 2, 'Restore job asks to delete files but names no account to confine that to.');
        }

        foreach ($cleanTargets as $cleanPath) {
            // The root every deletion must sit inside is the account's real
            // home, read from /etc/passwd -- not clean_root from the job file,
            // which only ever held the same value.
            $cleanAccount = $resolveHomeAccount();

            // Re-validated here, not trusted from the job file: this deletes
            // recursively.
            $cleanPath = $this->assertCleanable((string) $cleanPath, $cleanAccount->home);

            if (!file_exists($cleanPath) && !is_link($cleanPath)) {
                $this->log($job, 'Nothing to remove at ' . $cleanPath . '.');
                continue;
            }

            $this->log($job, 'Removing ' . $cleanPath . ' before restoring.');
            (new AccountFilesystem($cleanAccount))->remove($cleanPath);

            if (file_exists($cleanPath) || is_link($cleanPath)) {
                return $this->finish($job, Job::STATUS_FAILED, 1, 'Could not remove ' . $cleanPath . ' before restoring.');
            }
        }

        // Which paths are written by the account and which by root.
        //
        // Everything that lands inside a home is written by its account: that
        // tree is theirs to rearrange, so root must never be the one writing
        // into it. A user-level job is only ever that, in place or into a
        // directory of their own. An admin restore-a-user in place may also
        // carry the account's DirectAdmin tarball, which goes back into the
        // backups directory the administrator owns, and an admin restore into
        // a staging directory writes wherever the administrator chose. Only
        // those are still written as root. An in-place path in neither the
        // home nor the backups directory is refused here rather than trusting
        // that the page checked.
        $rootPaths = [];
        $accountPaths = [];
        if ($inPlace) {
            $adminBackupsDir = $config->adminBackupsDir();

            foreach ($paths as $path) {
                $path = PathGuard::normalize($path);

                if ($account === null && $adminBackupsDir !== '' && PathGuard::isWithin($path, $adminBackupsDir)) {
                    $rootPaths[] = $path;
                } elseif ($homeAccountName !== '' && PathGuard::isWithin($path, $resolveHomeAccount()->home)) {
                    $accountPaths[] = $path;
                } else {
                    return $this->finish($job, Job::STATUS_FAILED, 2, \sprintf(
                        'Refusing to restore %s in place: it is outside the account\'s home and the DirectAdmin backups directory.',
                        $path
                    ));
                }
            }
        } elseif ($account !== null) {
            // The destination was confined to the home above.
            $accountPaths = $paths;
        } else {
            $rootPaths = $paths;
        }

        $result = null;

        if ($accountPaths !== []) {
            $writer = new AccountFilesystem($resolveHomeAccount());

            if (!$inPlace) {
                $writer->mkdir($destination, 0750);
            }

            $this->log($job, \sprintf(
                'Extracting %d path(s) from %s into %s, written as %s.',
                \count($accountPaths),
                $archive,
                $inPlace ? $writer->account->home : $destination,
                $writer->account->username
            ));

            $result = $repository->runner()->runInto(
                $repository->exportTarArguments($archive, $accountPaths),
                $writer->untarCommand($destination),
                $writer->environment(),
                $this->sink($job),
            );

            if (!$result->isSuccessful()) {
                return $this->finish($job, Job::STATUS_FAILED, $result->exitCode, 'Restore failed: ' . $result->errorMessage());
            }
        }

        if ($rootPaths !== []) {
            $this->plugin->filesystem()->mkdir($destination, 0750);

            if (!is_dir($destination)) {
                return $this->finish($job, Job::STATUS_FAILED, 1, 'Unable to create the destination directory: ' . $destination);
            }

            $this->log($job, \sprintf('Extracting %d path(s) from %s into %s.', \count($rootPaths), $archive, $destination));

            $rootResult = $repository->runner()->run(
                $repository->extractArguments($archive, $rootPaths),
                BorgRunner::NO_TIMEOUT,
                $this->sink($job),
                $destination,
            );

            if (!$rootResult->isSuccessful()) {
                return $this->finish($job, Job::STATUS_FAILED, $rootResult->exitCode, 'Restore failed: ' . $rootResult->errorMessage());
            }

            // A warning from either half is a warning for the job.
            $result = $result !== null && $result->isWarning() ? $result : $rootResult;
        }

        if ($result === null) {
            return $this->finish($job, Job::STATUS_FAILED, 2, 'Restore job has no paths to extract.');
        }

        return $this->finish(
            $job,
            $result->isWarning() ? Job::STATUS_WARNING : Job::STATUS_SUCCESS,
            $result->exitCode,
            $inPlace ? 'Restored in place.' : 'Restored into ' . $destination
        );
    }

    /**
     * Put one archived file into an account's directory, under its own name.
     *
     * borg streams the file to stdout as root and the account writes it, under
     * a name DirectAdmin will not offer, then renames it into place. The
     * rename is what makes the file appear complete or not at all: DirectAdmin
     * lists that directory as backups to restore from, and a tarball growing
     * there for the length of the run, or a truncated one left by a job that
     * died, would be something a customer can pick. The account also sets the
     * mode, and nothing needs a chown, because the account created the file.
     */
    private function runDelivery(Job $job, Repository $repository, string $archive, string $path, Delivery $delivery): int
    {
        $delivery = $this->prepareDelivery($delivery);
        $writer = new AccountFilesystem($delivery->account);
        $incoming = $delivery->directory . '/.borg-incoming-' . $job->id;

        $this->log($job, \sprintf('Extracting %s from %s, written as %s.', $path, $archive, $delivery->account->username));

        try {
            $result = $repository->runner()->runInto(
                $repository->extractToStdoutArguments($archive, $path),
                $writer->writeCommand($incoming),
                $writer->environment(),
                $this->sink($job),
            );

            if (!$result->isSuccessful()) {
                return $this->finish($job, Job::STATUS_FAILED, $result->exitCode, 'Restore failed: ' . $result->errorMessage());
            }

            // The tarball holds one customer's databases and configuration,
            // and it sits in a directory they can reach.
            $writer->chmod($incoming, 0600);

            if (file_exists($delivery->target()) || is_link($delivery->target())) {
                $this->log($job, 'Replacing the existing ' . $delivery->target() . '.');
            }
            $writer->rename($incoming, $delivery->target());
        } finally {
            // Only still there if something above failed. It is not left behind
            // counting against the customer's quota.
            if (file_exists($incoming) || is_link($incoming)) {
                $writer->remove($incoming);
            }
        }

        $this->log($job, \sprintf(
            'Delivered %s to %s, owned by %s.',
            $delivery->filename,
            $delivery->directory,
            $delivery->account->username
        ));

        return $this->finish(
            $job,
            $result->isWarning() ? Job::STATUS_WARNING : Job::STATUS_SUCCESS,
            $result->exitCode,
            'Delivered ' . $delivery->filename . ' to ' . $delivery->directory . '.'
        );
    }

    /**
     * Where this restore has to leave the file, when that is not where borg
     * writes it.
     *
     * Restore Databases is the one caller: the tarball has to reach the
     * account's own backups directory, which is the only place DirectAdmin's
     * User Level restore screen reads, and that screen is the thing that
     * actually knows how to import databases.
     *
     * Re-derived from the job file rather than trusted, like every other path
     * this worker acts on.
     *
     * @param array<string,mixed> $params
     * @param string[]            $paths
     */
    private function resolveDelivery(array $params, array $paths, bool $inPlace): ?Delivery
    {
        $deliverTo = trim((string) ($params['deliver_to'] ?? ''));
        if ($deliverTo === '') {
            return null;
        }

        if ($inPlace) {
            throw new BorgPluginException('A restore cannot both go back in place and be delivered somewhere else.');
        }
        if (\count($paths) !== 1) {
            throw new BorgPluginException('A delivered restore carries exactly one file.');
        }

        $username = trim((string) ($params['restore_user'] ?? ''));
        if ($username === '') {
            throw new BorgPluginException('A delivered restore must name the account it belongs to.');
        }

        // The account is resolved from /etc/passwd, so the home that bounds the
        // delivery is the real one rather than whatever the job file says.
        $account = Account::resolve($username, $this->plugin->paths);

        $directory = $account->confine($deliverTo);
        if ($directory === rtrim($account->home, '/')) {
            throw new UnsafePathException('Refusing to deliver into the home directory itself.');
        }

        $filename = basename($paths[0]);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw new UnsafePathException('The restored path has no filename to deliver.');
        }

        return new Delivery($account, $directory, $filename);
    }

    /**
     * The delivery directory, created as the account if it is not there.
     *
     * The symlink and realpath checks are early refusals, not the guard: the
     * writing is done as the account, which is what holds when the customer
     * swaps the directory after this has looked at it. What they buy is a job
     * that says "is a symlink" up front instead of one that fails halfway with
     * a permission error.
     */
    private function prepareDelivery(Delivery $delivery): Delivery
    {
        $directory = $delivery->directory;

        if (is_link($directory)) {
            throw new UnsafePathException($directory . ' is a symlink; refusing to write through it.');
        }
        if (file_exists($directory) && !is_dir($directory)) {
            throw new UnsafePathException($directory . ' exists and is not a directory.');
        }

        if (!is_dir($directory)) {
            // DirectAdmin makes this directory itself the first time a user
            // takes a backup, so it is usually already here; creating it is for
            // the account that has never used the feature.
            (new AccountFilesystem($delivery->account))->mkdir($directory, 0750);
        }

        $resolved = realpath($directory);
        $home = realpath($delivery->account->home) ?: $delivery->account->home;

        if ($resolved === false || !PathGuard::isWithin($resolved, $home)) {
            throw new UnsafePathException(\sprintf('%s does not resolve to a path inside %s.', $directory, $delivery->account->home));
        }

        return $delivery->withDirectory($resolved);
    }

    /**
     * Assert a path may be deleted: inside $root, and never $root itself.
     *
     * Removing the home directory outright would take mail, cron, SSH keys and
     * anything else that is not part of a website with it, so a pre-clean is
     * always a subdirectory.
     *
     * @throws UnsafePathException
     */
    private function assertCleanable(string $path, string $root): string
    {
        if ($root === '' || $root === '/') {
            throw new UnsafePathException('No safe root for a pre-clean.');
        }

        $path = PathGuard::confine($path, $root);

        if ($path === rtrim($root, '/')) {
            throw new UnsafePathException('Refusing to delete ' . $root . ' itself; choose a subdirectory.');
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
