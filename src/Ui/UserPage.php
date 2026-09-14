<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Ui;

use Recranet\DirectAdminBorg\Borg\Archive;
use Recranet\DirectAdminBorg\Borg\ArchiveEntry;
use Recranet\DirectAdminBorg\Exception\BorgPluginException;
use Recranet\DirectAdminBorg\Http\PluginRequest;
use Recranet\DirectAdminBorg\Job\Job;
use Recranet\DirectAdminBorg\Plugin;
use Recranet\DirectAdminBorg\Security\Account;
use Recranet\DirectAdminBorg\Security\CsrfTokenizer;

/**
 * User-level page: browse the account's own files inside an archive and restore
 * them.
 *
 * This code runs as root (plugin.conf `user_run_as=root`) because the archive
 * and repository are root-owned, so every path from the request goes through
 * Account::confine() before it reaches borg, and restores are written into a
 * dedicated directory inside the home rather than over live files.
 */
final class UserPage
{
    private const MAX_RESTORE_ITEMS = 200;

    private FlashBag $flash;
    private ?Account $account = null;
    private ?string $accountError = null;

    /** @var array<string,string>|null archive name => timestamp */
    private ?array $archiveIndex = null;

    public function __construct(
        private readonly Plugin $plugin,
        private readonly PluginRequest $request,
        private readonly CsrfTokenizer $csrf
    ) {
        $this->flash = new FlashBag();

        try {
            $this->account = Account::resolve($request->username, $plugin->paths);
        } catch (\Throwable $e) {
            $this->accountError = $e->getMessage();
        }
    }

    public function render(): string
    {
        $config = $this->plugin->config()->load();
        $renderer = $this->plugin->renderer();
        $renderer->setUrlGenerator(fn (array $params = []): string => $this->request->url($params));

        $base = [
            'title'      => 'Backups',
            'subtitle'   => 'Restore files from the server backup into your home directory.',
            'messages'   => [],
            'enabled'    => $config->userRestoreEnabled(),
            'fatal'      => null,
            'available'  => false,
            'job'        => null,
            'browser'    => null,
            'archives'   => [],
            'recent_jobs' => [],
            'csrf_token' => '',
            'error'      => null,
            'home'       => '',
        ];

        if (!$config->userRestoreEnabled()) {
            return $renderer->render('user/page.html.twig', $base);
        }

        if ($this->account === null) {
            return $renderer->render('user/page.html.twig', $base + [] + ['fatal' => $this->accountError]);
        }

        $available = $config->isConfigured() && $this->plugin->repository()->runner()->isInstalled();
        if (!$available) {
            return $renderer->render('user/page.html.twig', array_merge($base, ['available' => false]));
        }

        if ($this->request->isPost()) {
            $this->handlePost();
        }

        $context = array_merge($base, [
            'available'   => true,
            'messages'    => $this->flash->all(),
            'csrf_token'  => $this->csrf->token($this->request->level, $this->request->username),
            'home'        => $this->account->home,
            'recent_jobs' => array_map([$this, 'jobToArray'], $this->plugin->jobs()->recent(10, $this->account->username)),
        ]);

        $jobId = $this->request->param('job');
        $archive = $this->request->param('archive');

        if ($jobId !== '') {
            $context = array_merge($context, $this->jobContext($jobId));
        } elseif ($archive !== '') {
            $context = array_merge($context, ['browser' => $this->browserContext($archive)]);
        } else {
            $context = array_merge($context, $this->archiveListContext());
        }

        // Resolved last: building the context can itself raise a message (an
        // unknown archive, a restore that is not the caller's), and those would
        // be lost if the list had been snapshotted earlier.
        $context['messages'] = $this->flash->all();

        return $renderer->render('user/page.html.twig', $context);
    }

    // ----------------------------------------------------------------- POST

    private function handlePost(): void
    {
        if (!$this->csrf->isValid($this->request->level, $this->request->username, $this->request->body()->getString('csrf_token'))) {
            $this->flash->error('Security token expired or missing. Please retry.');

            return;
        }

        if ($this->request->action() !== 'restore') {
            $this->flash->error('Unknown action.');

            return;
        }

        try {
            $this->restore();
        } catch (\Throwable $e) {
            $this->flash->error($e->getMessage());
        }
    }

    private function restore(): void
    {
        $account = $this->account;
        if ($account === null) {
            throw new BorgPluginException('Account could not be resolved.');
        }

        // Archive names come from the request, so check against the repository
        // rather than passing an arbitrary string to borg.
        $archive = $this->request->body()->getString('archive');
        if ($archive === '' || !isset($this->archiveIndex()[$archive])) {
            throw new BorgPluginException('Unknown archive.');
        }

        $paths = $this->request->bodyList('paths');
        if ($paths === []) {
            throw new BorgPluginException('Select at least one file or folder to restore.');
        }
        if (\count($paths) > self::MAX_RESTORE_ITEMS) {
            throw new BorgPluginException(sprintf('Select at most %d items at a time.', self::MAX_RESTORE_ITEMS));
        }

        // The boundary that keeps a user inside their own data.
        $confined = array_map(static fn (string $path) => $account->confine($path), $paths);

        $config = $this->plugin->config()->load();
        $destination = $account->restoreRoot($config->userRestoreDir());

        $job = $this->plugin->jobs()->create(Job::TYPE_RESTORE, $account->username, [
            'archive'     => $archive,
            'paths'       => $confined,
            'destination' => $destination,
            'chown_to'    => $account->username,
            'trigger'     => 'user',
        ]);
        $this->plugin->dispatcher()->dispatch($job);

        $this->flash->success(sprintf(
            'Restoring %d item(s) into %s. Your original files are not touched.',
            \count($confined),
            $destination
        ));
    }

    // -------------------------------------------------------------- context

    private function archiveListContext(): array
    {
        $listing = $this->plugin->repository()->listArchives();

        if (!$listing['result']->isSuccessful()) {
            // Do not surface borg's error text to a customer; it can contain
            // repository paths and host names.
            return ['error' => 'unavailable', 'archives' => []];
        }

        return [
            'error'    => null,
            'archives' => array_map(
                static fn (Archive $a) => ['name' => $a->name, 'time' => $a->time],
                \array_slice($listing['archives'], 0, 60)
            ),
        ];
    }

    private function browserContext(string $archive): array
    {
        $account = $this->account;

        if (!isset($this->archiveIndex()[$archive])) {
            $this->flash->error('Unknown archive.');

            return ['archive' => $archive, 'missing' => true, 'entries' => [], 'crumbs' => [], 'truncated' => false,
                'taken_at' => '', 'restore_dir' => ''];
        }

        $requested = $this->request->param('path', $account->home);

        try {
            $path = $account->confine($requested);
        } catch (\Throwable) {
            // Fall back to the home directory rather than confirming whether
            // the out-of-bounds path exists.
            $path = $account->home;
        }

        $listing = $this->plugin->repository()->listDirectory($archive, $path);
        $config = $this->plugin->config()->load();

        return [
            'archive'     => $archive,
            'taken_at'    => $this->archiveIndex()[$archive],
            'path'        => $path,
            'missing'     => !$listing->readable && $listing->isEmpty(),
            'entries'     => array_map([$this, 'entryToArray'], $listing->entries),
            'truncated'   => $listing->truncated,
            'crumbs'      => $this->crumbs($archive, $path),
            'restore_dir' => $account->restoreRoot($config->userRestoreDir()),
        ];
    }

    private function jobContext(string $jobId): array
    {
        $job = $this->plugin->jobs()->find($jobId);

        // A user may only ever see their own restores.
        if ($job === null || $job->owner() !== $this->account->username) {
            $this->flash->error('No such restore.');

            return ['job' => null];
        }

        return [
            'job'        => $this->jobToArray($job),
            'log'        => $this->plugin->jobs()->tail($job, 200),
            'status_url' => $this->request->baseUrl() . '/status.raw?job=' . rawurlencode($job->id),
        ];
    }

    /** @return array<int,array{label:string,url:string}> */
    private function crumbs(string $archive, string $path): array
    {
        $home = $this->account->home;

        $crumbs = [[
            'label' => basename($home),
            'url'   => $this->request->url(['archive' => $archive, 'path' => $home]),
        ]];

        $relative = trim(substr($path, \strlen($home)), '/');
        $accumulated = $home;

        foreach (array_filter(explode('/', $relative)) as $segment) {
            $accumulated .= '/' . $segment;
            $crumbs[] = [
                'label' => $segment,
                'url'   => $this->request->url(['archive' => $archive, 'path' => $accumulated]),
            ];
        }

        return $crumbs;
    }

    /** @return array<string,string> */
    private function archiveIndex(): array
    {
        if ($this->archiveIndex === null) {
            $this->archiveIndex = [];
            foreach ($this->plugin->repository()->listArchives()['archives'] as $archive) {
                if ($archive->name !== '') {
                    $this->archiveIndex[$archive->name] = $archive->time;
                }
            }
        }

        return $this->archiveIndex;
    }

    private function entryToArray(ArchiveEntry $entry): array
    {
        return [
            'path'      => $entry->path,
            'name'      => $entry->name,
            'directory' => $entry->isDirectory(),
            'size'      => $entry->size,
            'modified'  => $entry->modified,
        ];
    }

    private function jobToArray(Job $job): array
    {
        return [
            'id'          => $job->id,
            'type'        => $job->type(),
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
