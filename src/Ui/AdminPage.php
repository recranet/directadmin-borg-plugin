<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Ui;

use Recranet\DirectAdminBorg\Borg\Archive;
use Recranet\DirectAdminBorg\Borg\ArchiveEntry;
use Recranet\DirectAdminBorg\Config\Configuration;
use Recranet\DirectAdminBorg\Http\PluginRequest;
use Recranet\DirectAdminBorg\Job\Job;
use Recranet\DirectAdminBorg\Plugin;
use Recranet\DirectAdminBorg\Security\CsrfTokenizer;
use Recranet\DirectAdminBorg\Security\PathGuard;
use Recranet\DirectAdminBorg\Support\Format;

/**
 * Admin-level page: repository setup, backup settings, schedule, archives, jobs.
 */
final class AdminPage
{
    private const TABS = [
        'overview'   => 'Overview',
        'repository' => 'Repository',
        'backup'     => 'Backup',
        'archives'   => 'Archives',
        'jobs'       => 'Jobs',
    ];

    /**
     * Directories that a restore must never be pointed straight at. Restoring
     * an old /etc or /usr over a running system is not something that should be
     * one click away; a staging directory and a deliberate move is.
     */
    private const PROTECTED_DESTINATIONS = ['/', '/usr', '/etc', '/var', '/bin', '/sbin', '/lib', '/lib64', '/boot', '/home', '/root'];

    private FlashBag $flash;

    public function __construct(
        private readonly Plugin $plugin,
        private readonly PluginRequest $request,
        private readonly CsrfTokenizer $csrf
    ) {
        $this->flash = new FlashBag();
    }

    public function render(): string
    {
        if ($this->request->isPost()) {
            $this->handlePost();
        }

        // Reload: a POST may have changed the repository, schedule or secrets.
        $config = $this->plugin->config()->load();
        $repository = $this->plugin->repository();
        $runner = $repository->runner();

        $tab = $this->request->param('tab', 'overview');
        if (!isset(self::TABS[$tab])) {
            $tab = 'overview';
        }

        $context = [
            'title'      => 'Borg Backup',
            'subtitle'   => 'Server-wide BorgBackup repositories, schedules and restores.',
            'tabs'       => self::TABS,
            'active_tab' => $tab,
            'configured' => $config->isConfigured(),
            'config'     => $config->toArray(),
            'csrf_token' => $this->csrf->token($this->request->level, $this->request->username),
            'borg'       => [
                'installed'         => $runner->isInstalled(),
                'version'           => $runner->version(),
                'binary'            => $runner->binary(),
                'unsupported_major' => $runner->isUnsupportedMajor(),
                'uid'               => Format::currentUid(),
            ],
        ];

        $context += match ($tab) {
            'repository' => $this->repositoryContext($config),
            'backup'     => $this->backupContext($config),
            'archives'   => $this->archivesContext($config),
            'jobs'       => $this->jobsContext(),
            default      => $this->overviewContext($config),
        };

        // Resolved last: building a tab's context can itself raise a message
        // (an unknown job, an unreadable repository), and those would be lost
        // if the list had been snapshotted earlier.
        $context['messages'] = $this->flash->all();

        $renderer = $this->plugin->renderer();
        $renderer->setUrlGenerator(fn (array $params = []): string => $this->request->url($params));

        return $renderer->render('admin/page.html.twig', $context);
    }

    // ----------------------------------------------------------------- POST

    private function handlePost(): void
    {
        if (!$this->csrf->isValid($this->request->level, $this->request->username, $this->request->body()->getString('csrf_token'))) {
            $this->flash->error('Security token expired or missing. Please retry the action.');

            return;
        }

        try {
            match ($this->request->action()) {
                'save_repository' => $this->saveRepository(),
                'init_repository' => $this->initRepository(),
                'save_backup'     => $this->saveBackup(),
                'run_backup'      => $this->startJob(Job::TYPE_BACKUP),
                'run_prune'       => $this->startJob(Job::TYPE_PRUNE),
                'run_check'       => $this->startJob(Job::TYPE_CHECK),
                'break_lock'      => $this->breakLock(),
                'delete_archive'  => $this->deleteArchive(),
                'restore'         => $this->restore(),
                default           => $this->flash->error('Unknown action.'),
            };
        } catch (\Throwable $e) {
            $this->flash->error($e->getMessage());
        }
    }

    private function saveRepository(): void
    {
        $body = $this->request->body();

        $errors = $this->plugin->config()->save([
            'repository'  => $body->getString('repository'),
            'encryption'  => $body->getString('encryption'),
            'ssh_command' => $body->getString('ssh_command'),
        ]);

        foreach ($errors as $error) {
            $this->flash->error($error);
        }

        // An empty passphrase field means "leave unchanged"; clearing it is a
        // separate checkbox, so a blank form submit cannot destroy the key.
        if ($this->request->bodyBool('clear_passphrase')) {
            $this->plugin->config()->setPassphrase('');
            $this->flash->warning('Stored passphrase removed.');
        } elseif ($body->getString('passphrase') !== '') {
            $this->plugin->config()->setPassphrase($body->getString('passphrase'));
            $this->flash->success('Passphrase stored.');
        }

        if ($errors === []) {
            $this->flash->success('Repository settings saved.');
        }
    }

    private function initRepository(): void
    {
        $config = $this->plugin->config()->load();

        if (!$config->isConfigured()) {
            $this->flash->error('Set a repository location first.');

            return;
        }
        if ($config->requiresPassphrase() && !$config->hasPassphrase()) {
            $this->flash->error('This encryption mode needs a passphrase. Save one before initialising.');

            return;
        }

        $result = $this->plugin->repository()->initialize();

        if ($result->isSuccessful()) {
            $this->flash->success('Repository initialised.');

            return;
        }

        $message = $result->errorMessage();
        if (stripos($message, 'already exists') !== false) {
            $this->flash->warning('A repository already exists at that location; nothing was changed.');

            return;
        }

        $this->flash->error('Could not initialise the repository: ' . $message);
    }

    private function saveBackup(): void
    {
        $body = $this->request->body();

        $errors = $this->plugin->config()->save([
            'source_paths'         => $body->getString('source_paths'),
            'exclude_patterns'     => $body->getString('exclude_patterns'),
            'compression'          => $body->getString('compression'),
            'archive_name'         => $body->getString('archive_name'),
            'archive_prefix'       => $body->getString('archive_prefix'),
            'one_file_system'      => $this->request->bodyBool('one_file_system'),
            'prune_enabled'        => $this->request->bodyBool('prune_enabled'),
            'keep_daily'           => $body->getString('keep_daily'),
            'keep_weekly'          => $body->getString('keep_weekly'),
            'keep_monthly'         => $body->getString('keep_monthly'),
            'compact_after_prune'  => $this->request->bodyBool('compact_after_prune'),
            'schedule_enabled'     => $this->request->bodyBool('schedule_enabled'),
            'schedule_minute'      => $body->getString('schedule_minute'),
            'schedule_hour'        => $body->getString('schedule_hour'),
            'run_after_da_backups' => $this->request->bodyBool('run_after_da_backups'),
            'user_restore_enabled' => $this->request->bodyBool('user_restore_enabled'),
            'user_restore_dir'     => $body->getString('user_restore_dir'),
        ]);

        foreach ($errors as $error) {
            $this->flash->error($error);
        }
        if ($errors !== []) {
            return;
        }

        try {
            $this->plugin->cron()->apply($this->plugin->config()->load());
            $this->flash->success('Backup settings saved.');
        } catch (\Throwable $e) {
            $this->flash->warning('Settings saved, but the cron file could not be written: ' . $e->getMessage());
        }
    }

    private function startJob(string $type): void
    {
        if (!$this->ensureReady()) {
            return;
        }

        $config = $this->plugin->config()->load();

        $params = $type === Job::TYPE_BACKUP
            ? ['prune' => $config->pruneEnabled(), 'trigger' => 'manual']
            : ['trigger' => 'manual'];

        $job = $this->plugin->jobs()->create($type, $this->request->username, $params);
        $this->plugin->dispatcher()->dispatch($job);

        $this->flash->success(sprintf('%s started. Follow it under Jobs.', ucfirst($type)));
    }

    private function breakLock(): void
    {
        if (!$this->ensureReady()) {
            return;
        }

        $result = $this->plugin->repository()->breakLock();

        if ($result->isSuccessful()) {
            $this->flash->success('Repository lock released.');
        } else {
            $this->flash->error('Could not release the lock: ' . $result->errorMessage());
        }
    }

    private function deleteArchive(): void
    {
        if (!$this->ensureReady()) {
            return;
        }

        $archive = $this->request->body()->getString('archive');
        if ($archive === '') {
            $this->flash->error('No archive selected.');

            return;
        }

        // Deleting an archive cannot be undone, so require the name typed back.
        if ($this->request->body()->getString('confirm') !== $archive) {
            $this->flash->error('Type the archive name exactly to confirm deletion.');

            return;
        }

        $result = $this->plugin->repository()->deleteArchive($archive);

        if ($result->isSuccessful()) {
            $this->flash->success(sprintf('Archive "%s" deleted.', $archive));
        } else {
            $this->flash->error('Could not delete the archive: ' . $result->errorMessage());
        }
    }

    private function restore(): void
    {
        if (!$this->ensureReady()) {
            return;
        }

        $archive = $this->request->body()->getString('archive');
        $paths = $this->request->bodyList('paths');
        $destination = trim($this->request->body()->getString('destination'));

        if ($archive === '' || $paths === []) {
            $this->flash->error('Select at least one item to restore.');

            return;
        }
        if ($destination === '') {
            $this->flash->error('A restore destination is required.');

            return;
        }

        $destination = PathGuard::normalize($destination);

        if (\in_array($destination, self::PROTECTED_DESTINATIONS, true)) {
            $this->flash->error(sprintf(
                'Refusing to restore directly into %s. Restore into a staging directory and move files from there.',
                $destination
            ));

            return;
        }

        $normalized = array_map([PathGuard::class, 'normalize'], $paths);

        $job = $this->plugin->jobs()->create(Job::TYPE_RESTORE, $this->request->username, [
            'archive'     => $archive,
            'paths'       => $normalized,
            'destination' => $destination,
            'trigger'     => 'manual',
        ]);
        $this->plugin->dispatcher()->dispatch($job);

        $this->flash->success(sprintf(
            'Restoring %d item(s) into %s. Follow it under Jobs.',
            \count($normalized),
            $destination
        ));
    }

    private function ensureReady(): bool
    {
        if (!$this->plugin->repository()->runner()->isInstalled()) {
            $this->flash->error('borg is not installed on this server.');

            return false;
        }
        if (!$this->plugin->config()->load()->isConfigured()) {
            $this->flash->error('Configure a repository first.');

            return false;
        }

        return true;
    }

    // -------------------------------------------------------------- context

    private function overviewContext(Configuration $config): array
    {
        $status = [
            'repository'      => $config->repository(),
            'encryption'      => $config->encryption(),
            'schedule'        => $config->describeSchedule(),
            'last_backup'     => 'never',
            'archive_count'   => null,
            'repository_size' => null,
            'original_size'   => null,
            'error'           => null,
        ];

        foreach ($this->plugin->jobs()->recent(40, null, Job::TYPE_BACKUP) as $job) {
            if ($job->isFinished()) {
                $status['last_backup'] = sprintf('%s (%s)', Format::age((string) $job->get('finished_at')), $job->status());
                break;
            }
        }

        if ($config->isConfigured() && $this->plugin->repository()->runner()->isInstalled()) {
            $info = $this->plugin->repository()->info();

            if ($info->isSuccessful()) {
                $stats = $info->json()['cache']['stats'] ?? null;
                if (\is_array($stats)) {
                    $status['repository_size'] = $stats['unique_csize'] ?? null;
                    $status['original_size'] = $stats['total_size'] ?? null;
                }
                $status['archive_count'] = \count($this->plugin->repository()->listArchives()['archives']);
            } else {
                $status['error'] = $info->errorMessage();
            }
        }

        return ['status' => $status];
    }

    private function repositoryContext(Configuration $config): array
    {
        return [
            'encryption_modes' => Configuration::ENCRYPTION_MODES,
            'has_passphrase'   => $config->hasPassphrase(),
            'passphrase_file'  => $this->plugin->paths->passphraseFile(),
        ];
    }

    private function backupContext(Configuration $config): array
    {
        return [
            'cron_file'            => $this->plugin->cron()->file(),
            'schedule_description' => $config->describeSchedule(),
        ];
    }

    private function archivesContext(Configuration $config): array
    {
        if (!$config->isConfigured()) {
            return ['archives' => [], 'browser' => null, 'error' => null];
        }

        $archive = $this->request->param('archive');
        if ($archive !== '') {
            return ['archives' => [], 'error' => null, 'browser' => $this->browserContext($archive)];
        }

        $listing = $this->plugin->repository()->listArchives();

        return [
            'browser'  => null,
            'error'    => $listing['result']->isSuccessful() ? null : $listing['result']->errorMessage(),
            'archives' => array_map(
                static fn (Archive $a) => ['name' => $a->name, 'time' => $a->time],
                $listing['archives']
            ),
        ];
    }

    private function browserContext(string $archive): array
    {
        $path = $this->request->param('path', '/');

        try {
            $path = PathGuard::normalize($path);
        } catch (\Throwable) {
            $path = '/';
        }

        $listing = $this->plugin->repository()->listDirectory($archive, $path);

        return [
            'archive'             => $archive,
            'path'                => $path,
            'crumbs'              => $this->crumbs($archive, $path),
            'entries'             => array_map([$this, 'entryToArray'], $listing->entries),
            'truncated'           => $listing->truncated,
            'error'               => $listing->readable ? null : $listing->error,
            'default_destination' => '/home/admin/borg_restore',
        ];
    }

    private function jobsContext(): array
    {
        $jobId = $this->request->param('job');

        if ($jobId !== '') {
            $job = $this->plugin->jobs()->find($jobId);

            if ($job === null) {
                $this->flash->error('No such job.');

                return ['job' => null, 'jobs' => []];
            }

            return [
                'job'        => $this->jobToArray($job),
                'log'        => $this->plugin->jobs()->tail($job, 400),
                'status_url' => $this->request->baseUrl() . '/status.raw?job=' . rawurlencode($job->id),
                'jobs'       => [],
            ];
        }

        return [
            'job'  => null,
            'jobs' => array_map([$this, 'jobToArray'], $this->plugin->jobs()->recent(30)),
        ];
    }

    /** @return array<int,array{label:string,url:string}> */
    private function crumbs(string $archive, string $path): array
    {
        $crumbs = [['label' => '/', 'url' => $this->request->url(['tab' => 'archives', 'archive' => $archive, 'path' => '/'])]];

        $accumulated = '';
        foreach (array_filter(explode('/', $path)) as $segment) {
            $accumulated .= '/' . $segment;
            $crumbs[] = [
                'label' => $segment,
                'url'   => $this->request->url(['tab' => 'archives', 'archive' => $archive, 'path' => $accumulated]),
            ];
        }

        return $crumbs;
    }

    private function entryToArray(ArchiveEntry $entry): array
    {
        return [
            'path'      => $entry->path,
            'name'      => $entry->name,
            'directory' => $entry->isDirectory(),
            'size'      => $entry->size,
            'mode'      => $entry->mode,
            'owner'     => $entry->owner,
            'modified'  => $entry->modified,
        ];
    }

    private function jobToArray(Job $job): array
    {
        return [
            'id'          => $job->id,
            'type'        => $job->type(),
            'owner'       => $job->owner(),
            'status'      => $job->status(),
            'badge'       => $job->badge(),
            'message'     => $job->message(),
            'created_at'  => (string) $job->get('created_at'),
            'started_at'  => (string) $job->get('started_at'),
            'finished_at' => (string) $job->get('finished_at'),
            'stats'       => $job->stats(),
            'running'     => $job->isRunning(),
        ];
    }
}
