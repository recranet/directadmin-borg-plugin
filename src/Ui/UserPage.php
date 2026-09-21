<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Ui;

use Recranet\DirectAdminBorg\Borg\Archive;
use Recranet\DirectAdminBorg\Borg\ArchiveEntry;
use Recranet\DirectAdminBorg\Borg\ArchiveName;
use Recranet\DirectAdminBorg\Borg\DirectoryListing;
use Recranet\DirectAdminBorg\Borg\Repository;
use Recranet\DirectAdminBorg\Config\Configuration;
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
            'title'       => 'Borg Backup',
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
            'available'  => true,
            'messages'   => $this->flash->all(),
            'csrf_token' => $this->csrf->token($this->request->level, $this->request->username),
            'home'       => $account->home,
            // Restores only: a customer who prepares a backup gets the
            // progress card for it on the spot, and "Your recent restores"
            // stays a list of things that changed their files.
            'recent_jobs' => array_map(
                [$this, 'jobToArray'],
                $this->plugin->jobs()->recent(10, $account->username, Job::TYPE_RESTORE)
            ),
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
            $context = array_merge($context, ['panel' => $this->panelContext($archive, $account, $config)]);
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
                'restore'           => $this->restore(),
                'restore_domains'   => $this->restoreTree('domains'),
                'restore_email'     => $this->restoreTree('imap'),
                'restore_databases' => $this->restoreDatabases(),
                'build_index'       => $this->buildIndex(),
                default             => $this->flash->error('Unknown action.'),
            };
        } catch (\Throwable $e) {
            $this->flash->error($e->getMessage());
        }
    }

    /**
     * Put the website or the mail back, in one click.
     *
     * The same operation Admin Level offers, for the account making the
     * request, and now with the same two confirmations.
     *
     * The tick is the ordinary one: this writes over live files and there is no
     * undo, so it is the moment the customer says the current files can go.
     *
     * The pre-clean is the other thing entirely, and the reason it asks for the
     * username in writing. A restore overwrites: a file in the backup replaces
     * the one that is there, and a file that is not in the backup is left
     * alone -- which is why a webshell dropped in last week survives one.
     * Deleting first is what makes the directory exactly what the backup held,
     * and it takes everything added since with it: new sites, uploads, anything
     * a customer put there this month. Same tick-and-type as Admin Level,
     * because the mistake it prevents is the same mistake.
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

        // Confined and re-checked inside queue(), which is the one place that
        // decides what path the job carries -- the same guard the admin
        // pre-clean goes through, rather than a second copy of it here.
        $cleanPaths = [];
        if ($this->request->bodyBool('clean_first')) {
            if ($this->request->body()->getString('clean_confirm') !== $account->username) {
                throw new BorgPluginException(\sprintf('Type "%s" to confirm deleting everything in %s before restoring it.', $account->username, $target));
            }

            $cleanPaths[] = $target;
        }

        $job = (new AccountTreeRestore($this->plugin))
            ->queue($archive, $account, $tree, $account->username, 'user', $cleanPaths);

        if ($cleanPaths !== []) {
            // Not the overlay wording with a clause bolted on: "anything added
            // since is left alone" is the opposite of what was just asked for,
            // and that sentence is the one thing the customer has to have read
            // correctly.
            $this->flash->success(\sprintf(
                'Emptying %s and putting the backup back in its place, so what is left is exactly '
                . 'what that backup held -- anything added since is gone. '
                . 'Follow it under "Your recent restores" (%s).',
                $target,
                $job->id
            ));

            return;
        }

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

    /**
     * Put the account's DirectAdmin backup where DirectAdmin's own restore
     * screen can see it, so the customer can import their databases.
     *
     * The same restore Admin Level offers, ending in the same place. It is if
     * anything more at home here: the screen that imports the dump is a User
     * Level screen, so the person who has to drive it is already logged in
     * where the file lands.
     *
     * Only ever this account's own tarball. DirectAdmin hands whatever is in
     * /home/<user>/backups to the restoring user, so the dot-anchored match in
     * Repository::pickAdminBackup() is what stops `jean` being offered
     * `user.admin.beaujean.tar.zst` -- and the account is the logged-in one
     * rather than anything the request carries.
     */
    private function restoreDatabases(): void
    {
        $account = $this->account;
        if ($account === null) {
            throw new BorgPluginException('Account could not be resolved.');
        }

        $config = $this->plugin->config()->load();
        if (!$config->restoreAdminBackup()) {
            throw new BorgPluginException('Database restores are switched off on this server. Ask your hosting provider.');
        }

        $archive = $this->request->body()->getString('archive');
        if ($archive === '' || !isset($this->archiveTimes()[$archive])) {
            throw new BorgPluginException('Unknown backup.');
        }

        $adminBackup = $this->findAdminBackup($archive, $config, $account);
        if ($adminBackup === null) {
            throw new BorgPluginException('There is no DirectAdmin backup of your account in this backup, and your databases are only in that.');
        }

        // DirectAdmin's own name for this directory, derived from the resolved
        // account rather than the request. The worker resolves it again from
        // /etc/passwd and re-checks it before writing, because it runs as root
        // into a directory the customer owns.
        $backupsDir = $account->confine($account->home . '/backups');

        $job = $this->plugin->jobs()->create(Job::TYPE_RESTORE, $account->username, [
            'archive'      => $archive,
            'paths'        => [$adminBackup->path],
            'destination'  => $backupsDir,
            'restore_user' => $account->username,
            'deliver_to'   => $backupsDir,
            'trigger'      => 'user',
        ]);
        $this->plugin->dispatcher()->dispatch($job);

        $this->flash->success(\sprintf(
            'Putting %s into %s. When it finishes, go to Create/Restore Backups -> Restore Backups, '
            . 'pick that file, tick Databases and restore. Nothing changes until you do. '
            . 'The file counts against your disk space until you delete it. '
            . 'Follow it under "Your recent restores" (%s).',
            basename($adminBackup->path),
            $backupsDir,
            $job->id
        ));
    }

    /**
     * Queue the one-off scan that makes a backup browsable.
     *
     * The same job Admin Level queues, for the same reason: borg cannot list a
     * single directory, so every screen here would otherwise cost a full pass
     * over the backup -- minutes on a hosting server, per click, while the
     * customer stares at a page that never arrives.
     *
     * One at a time, across the whole server. An administrator indexing five
     * backups at once has decided to; sixty customers each queueing their own
     * would be a scan per customer over the same repository, which is the one
     * way this page could hurt the server it restores from.
     */
    private function buildIndex(): void
    {
        $archive = $this->request->body()->getString('archive');
        if ($archive === '' || !isset($this->archiveTimes()[$archive])) {
            throw new BorgPluginException('Unknown backup.');
        }

        $running = $this->runningIndexJob();
        if ($running !== null) {
            throw new BorgPluginException(($running->params()['archive'] ?? '') === $archive ? 'This backup is already being prepared. It will be ready shortly.' : 'Another backup is being prepared right now. Try again when it has finished.');
        }

        $job = $this->plugin->jobs()->create(Job::TYPE_INDEX, $this->request->username, [
            'archive' => $archive,
            // The administrator's setting decides this, not the customer: it
            // trades index size against being able to pick out single files,
            // and it is the server's disk either way.
            'files' => $this->plugin->config()->load()->indexFiles(),
        ]);
        $this->plugin->dispatcher()->dispatch($job);

        $this->flash->success('Preparing this backup. It only has to happen once; the page follows along below.');
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

        if (!$this->plugin->archiveIndex()->exists($archive)) {
            return $this->indexContext($archive) + ['restore_dir' => $account->home, 'path' => $account->home];
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
    private function panelContext(string $archive, Account $account, Configuration $config): array
    {
        if (!isset($this->archiveTimes()[$archive])) {
            $this->flash->error('Unknown backup.');

            return ['archive' => $archive, 'missing' => true, 'taken_at' => '', 'home' => $account->home];
        }

        if (!$this->plugin->archiveIndex()->exists($archive)) {
            return $this->indexContext($archive) + ['home' => $account->home];
        }

        $present = [];
        foreach ($this->listDirectory($archive, $account->home)->entries as $entry) {
            if ($entry->isDirectory()) {
                $present[$entry->name] = true;
            }
        }

        $adminBackup = $config->restoreAdminBackup()
            ? $this->findAdminBackup($archive, $config, $account)
            : null;

        return [
            'archive'      => $archive,
            'missing'      => false,
            'needs_index'  => false,
            'taken_at'     => $this->archiveTimes()[$archive],
            'home'         => $account->home,
            'has_domains'  => isset($present['domains']),
            'has_email'    => isset($present['imap']),
            'domains_path' => AccountTreeRestore::path($account, 'domains'),
            'email_path'   => AccountTreeRestore::path($account, 'imap'),
            // The databases are the one thing the home directory does not hold,
            // so this panel says so whether or not the tarball is there.
            'databases_on'      => $config->restoreAdminBackup(),
            'admin_backup_name' => $adminBackup === null ? null : basename($adminBackup->path),
            'admin_backup_size' => $adminBackup?->size,
            'backups_dir'       => $account->home . '/backups',
        ];
    }

    /**
     * The "not ready yet" screen, and the job that makes it ready.
     *
     * Admin Level has had this since indexing existed; User Level used to ask
     * borg directly instead, which is a full pass over the backup for one
     * directory listing and left the customer on a page that never finished
     * loading. The flow is now the admin one exactly: a job, a log, and a page
     * that reloads itself when the scan is done.
     *
     * @return array<string,mixed>
     */
    private function indexContext(string $archive): array
    {
        $running = $this->runningIndexJob($archive);

        return [
            'archive'          => $archive,
            'missing'          => false,
            'needs_index'      => true,
            'taken_at'         => $this->archiveTimes()[$archive] ?? '',
            'index_job'        => $running === null ? null : $this->jobToArray($running),
            'index_log'        => $running === null ? '' : $this->plugin->jobs()->tail($running, 200),
            'index_status_url' => $running === null
                ? ''
                : $this->request->baseUrl() . '/status.raw?job=' . rawurlencode($running->id),
            'entries'   => [],
            'crumbs'    => [],
            'truncated' => false,
        ];
    }

    /**
     * An index job still running, for this backup or for any other.
     *
     * "Any other" is the point when nothing is passed: the customer-facing
     * button allows one scan at a time across the server, so the question it
     * has to answer is whether the repository is busy, not whether this
     * particular backup is.
     */
    private function runningIndexJob(string $archive = ''): ?Job
    {
        foreach ($this->plugin->jobs()->recent(20, null, Job::TYPE_INDEX) as $job) {
            if ($job->isFinished()) {
                continue;
            }
            if ($archive === '' || ($job->params()['archive'] ?? null) === $archive) {
                return $job;
            }
        }

        return null;
    }

    /**
     * This account's DirectAdmin backup inside the archive, or nothing.
     *
     * Through the index, which records that directory's files even when the
     * index is otherwise directories-only, precisely so this stays cheap. There
     * is no borg fallback here on purpose: asking borg costs a full scan, and
     * the whole point of the screen this feeds is that a customer never waits
     * for one.
     */
    private function findAdminBackup(string $archive, Configuration $config, Account $account): ?ArchiveEntry
    {
        $index = $this->plugin->archiveIndex();

        if (!$index->exists($archive)) {
            return null;
        }

        return Repository::pickAdminBackup(
            $index->listDirectory($archive, $config->adminBackupsDir())->entries,
            $account->username
        );
    }

    /**
     * One directory out of an archive, for a path already confined to the home.
     *
     * Index only, and the callers guarantee there is one. Falling back to borg
     * is what this page used to do, and it is why it hung: borg reads the whole
     * archive however little you ask for, so one customer opening one folder
     * cost a full scan -- with nothing on screen to say why, no way to follow
     * it, and another scan waiting on the next click.
     */
    private function listDirectory(string $archive, string $path): DirectoryListing
    {
        return $this->plugin->archiveIndex()->listDirectory($archive, $path);
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
