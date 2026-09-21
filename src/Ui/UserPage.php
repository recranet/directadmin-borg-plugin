<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Ui;

use Recranet\DirectAdminBorg\Borg\Archive;
use Recranet\DirectAdminBorg\Borg\ArchiveEntry;
use Recranet\DirectAdminBorg\Borg\ArchiveName;
use Recranet\DirectAdminBorg\Borg\DirectoryListing;
use Recranet\DirectAdminBorg\Exception\BorgPluginException;
use Recranet\DirectAdminBorg\Http\PluginRequest;
use Recranet\DirectAdminBorg\Job\AccountTreeRestore;
use Recranet\DirectAdminBorg\Job\Job;
use Recranet\DirectAdminBorg\Plugin;
use Recranet\DirectAdminBorg\Security\Account;
use Recranet\DirectAdminBorg\Security\CsrfTokenizer;

/**
 * User-level page: pick a backup date, put the website or the mail back, and
 * browse the account's own files for anything else.
 *
 * The shape deliberately matches the admin account panel with the account
 * picker removed, because the account is not a choice here -- it is whoever is
 * logged in. A customer should not have to know that "my site is broken" means
 * `domains` and "my mail is gone" means `imap`; the admin screen does that
 * translation for an administrator, and there is no reason the person who
 * actually has the problem gets less.
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
        private readonly CsrfTokenizer $csrf,
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
            'title'       => 'Backups',
            'subtitle'    => 'Restore files from the server backup into your home directory.',
            'messages'    => [],
            'enabled'     => $config->userRestoreEnabled(),
            'fatal'       => null,
            'available'   => false,
            'job'         => null,
            'panel'       => null,
            'browser'     => null,
            'archives'    => [],
            'recent_jobs' => [],
            'csrf_token'  => '',
            'error'       => null,
            'home'        => '',
        ];

        if (!$config->userRestoreEnabled()) {
            return $renderer->render('user/page.html.twig', $base);
        }

        $account = $this->account;
        if ($account === null) {
            return $renderer->render('user/page.html.twig', array_merge($base, ['fatal' => $this->accountError]));
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
            'home'        => $account->home,
            'recent_jobs' => array_map([$this, 'jobToArray'], $this->plugin->jobs()->recent(10, $account->username)),
        ]);

        $jobId = $this->request->param('job');
        $archive = $this->request->param('archive');

        // An archive on its own opens the account panel; a path within it opens
        // the browser. Both restore forms post their archive back, so a restore
        // returns to the screen it was started from rather than to the top.
        if ($jobId !== '') {
            $context = array_merge($context, $this->jobContext($jobId, $account));
        } elseif ($archive !== '' && $this->request->param('path') !== '') {
            $context = array_merge($context, ['browser' => $this->browserContext($archive, $account)]);
        } elseif ($archive !== '') {
            $context = array_merge($context, ['panel' => $this->panelContext($archive, $account)]);
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

        try {
            match ($this->request->action()) {
                'restore'         => $this->restore(),
                'restore_domains' => $this->restoreTree('domains'),
                'restore_email'   => $this->restoreTree('imap'),
                default           => $this->flash->error('Unknown action.'),
            };
        } catch (\Throwable $e) {
            $this->flash->error($e->getMessage());
        }
    }

    /**
     * Put the website or the mail back, in one click.
     *
     * The same operation Admin Level offers, for the account making the
     * request. Two things it deliberately does not inherit from that screen:
     *
     * The pre-clean is not offered. Deleting the directory before extracting is
     * the malware-cleanup path -- irreversible, and it takes everything the
     * archive does not contain with it. That is a decision for whoever is
     * handling the incident, not a checkbox on a customer's page.
     *
     * And it asks for a tick. An administrator restoring in place has made a
     * considered decision and typed a username to get there; a customer has
     * clicked one large button and may not have read the warning beside it.
     * The tick is the moment they say the current files can go.
     */
    private function restoreTree(string $tree): void
    {
        $account = $this->account;
        if ($account === null) {
            throw new BorgPluginException('Account could not be resolved.');
        }

        $archive = $this->request->body()->getString('archive');
        if ($archive === '' || !isset($this->archiveTimes()[$archive])) {
            throw new BorgPluginException('Unknown backup.');
        }

        $target = AccountTreeRestore::path($account, $tree);

        if (!$this->request->bodyBool('confirm')) {
            throw new BorgPluginException(\sprintf('Tick the box to confirm that what is in %s now should be replaced with the backup.', $target));
        }

        $job = (new AccountTreeRestore($this->plugin))
            ->queue($archive, $account, $tree, $account->username, 'user');

        $this->flash->success($tree === 'imap'
            ? \sprintf(
                'Restoring your mailboxes back into %s. Mail that has arrived since that backup '
                . 'is left where it is, so this adds messages back rather than replacing your mailbox. '
                . 'Your mail program may re-download messages afterwards. Follow it under "Your recent restores" (%s).',
                $target,
                $job->id
            )
            : \sprintf(
                'Restoring your website files back into %s. A file of the same name is overwritten '
                . 'with the older version and the current one is not kept; anything added since that '
                . 'backup is left alone. Follow it under "Your recent restores" (%s).',
                $target,
                $job->id
            ));
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
        if ($archive === '' || !isset($this->archiveTimes()[$archive])) {
            throw new BorgPluginException('Unknown backup.');
        }

        $paths = $this->request->bodyList('paths');
        if ($paths === []) {
            throw new BorgPluginException('Select at least one file or folder to restore.');
        }
        if (\count($paths) > self::MAX_RESTORE_ITEMS) {
            throw new BorgPluginException(\sprintf('Select at most %d items at a time.', self::MAX_RESTORE_ITEMS));
        }

        // The boundary that keeps a user inside their own data. It matters more
        // now than it did: these restores go back over the live files, so the
        // only thing standing between a customer and someone else's data is
        // this. Every path is confined, and the worker re-checks against the
        // same home rather than trusting what the job file says.
        $confined = array_map(static fn (string $path) => $account->confine($path), $paths);

        $job = $this->plugin->jobs()->create(Job::TYPE_RESTORE, $account->username, [
            'archive' => $archive,
            'paths'   => $confined,
            // borg strips the leading slash and writes relative to the working
            // directory, so "/" is what puts a file back where it came from.
            'destination' => '/',
            'in_place'    => true,
            // Not chown_to: an extract as root restores the ownership recorded
            // in the archive, which for this account's own files is already
            // right. confine_to is what makes the worker re-check every path
            // against this home before it writes anything.
            'confine_to' => $account->username,
            'trigger'    => 'user',
        ]);
        $this->plugin->dispatcher()->dispatch($job);

        $this->flash->success(\sprintf(
            'Restoring %d item(s) back into %s. Files of the same name are overwritten; '
            . 'anything you have created since that backup is left alone.',
            \count($confined),
            $account->home
        ));
    }

    // -------------------------------------------------------------- context

    /** @return array<string,mixed> */
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
                static fn (Archive $a) => [
                    'name'     => $a->name,
                    'taken_at' => ArchiveName::date($a->name) ?? $a->time,
                ],
                \array_slice($listing['archives'], 0, 60)
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function browserContext(string $archive, Account $account): array
    {
        if (!isset($this->archiveTimes()[$archive])) {
            $this->flash->error('Unknown backup.');

            return ['archive' => $archive, 'missing' => true, 'entries' => [], 'crumbs' => [], 'truncated' => false,
                'taken_at'    => '', 'restore_dir' => ''];
        }

        $requested = $this->request->param('path', $account->home);

        try {
            $path = $account->confine($requested);
        } catch (\Throwable) {
            // Fall back to the home directory rather than confirming whether
            // the out-of-bounds path exists.
            $path = $account->home;
        }

        $listing = $this->listDirectory($archive, $path);

        return [
            'archive'     => $archive,
            'taken_at'    => $this->archiveTimes()[$archive],
            'path'        => $path,
            'missing'     => !$listing->readable && $listing->isEmpty(),
            'entries'     => array_map([$this, 'entryToArray'], $listing->entries),
            'truncated'   => $listing->truncated,
            'crumbs'      => $this->crumbs($archive, $path, $account),
            'restore_dir' => $account->home,
        ];
    }

    /**
     * The account panel: the two restores that get asked for, for this account.
     *
     * Which of them is offered depends on what is actually in the archive, the
     * same way the admin panel decides it. Offering "Restore Email" for a
     * backup that has no imap directory would queue a job that extracts nothing
     * and reports success, which is worse than saying so.
     *
     * @return array<string,mixed>
     */
    private function panelContext(string $archive, Account $account): array
    {
        if (!isset($this->archiveTimes()[$archive])) {
            $this->flash->error('Unknown backup.');

            return ['archive' => $archive, 'missing' => true, 'taken_at' => '', 'home' => $account->home];
        }

        $present = [];
        foreach ($this->listDirectory($archive, $account->home)->entries as $entry) {
            if ($entry->isDirectory()) {
                $present[$entry->name] = true;
            }
        }

        return [
            'archive'      => $archive,
            'missing'      => false,
            'taken_at'     => $this->archiveTimes()[$archive],
            'home'         => $account->home,
            'has_domains'  => isset($present['domains']),
            'has_email'    => isset($present['imap']),
            'domains_path' => AccountTreeRestore::path($account, 'domains'),
            'email_path'   => AccountTreeRestore::path($account, 'imap'),
        ];
    }

    /**
     * One directory out of an archive, for a path already confined to the home.
     *
     * Through the index when an administrator has built one -- instant, and the
     * same view the admin gets. Without it, ask borg for that one subtree:
     * slower, but a customer should not have to wait for an administrator to
     * index an archive before they can restore from it. The path is confined
     * before it gets here, so the scan is bounded either way.
     */
    private function listDirectory(string $archive, string $path): DirectoryListing
    {
        $index = $this->plugin->archiveIndex();

        return $index->exists($archive)
            ? $index->listDirectory($archive, $path)
            : $this->plugin->repository()->listDirectory($archive, $path);
    }

    /** @return array<string,mixed> */
    private function jobContext(string $jobId, Account $account): array
    {
        $job = $this->plugin->jobs()->find($jobId);

        // A user may only ever see their own restores.
        if ($job === null || $job->owner() !== $account->username) {
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
    private function crumbs(string $archive, string $path, Account $account): array
    {
        $home = $account->home;

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
    private function archiveTimes(): array
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

    /** @return array<string,mixed> */
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

    /** @return array<string,mixed> */
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
            'running'     => $job->isRunning(),
        ];
    }
}
