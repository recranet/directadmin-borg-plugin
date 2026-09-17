<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Ui;

use Recranet\DirectAdminBorg\Borg\Archive;
use Recranet\DirectAdminBorg\Borg\ArchiveEntry;
use Recranet\DirectAdminBorg\Borg\ArchiveName;
use Recranet\DirectAdminBorg\Borg\Repository;
use Recranet\DirectAdminBorg\Config\Configuration;
use Recranet\DirectAdminBorg\Exception\BorgPluginException;
use Recranet\DirectAdminBorg\Http\PluginRequest;
use Recranet\DirectAdminBorg\Job\Job;
use Recranet\DirectAdminBorg\Plugin;
use Recranet\DirectAdminBorg\Security\Account;
use Recranet\DirectAdminBorg\Security\CsrfTokenizer;
use Recranet\DirectAdminBorg\Security\PathGuard;
use Recranet\DirectAdminBorg\Support\Format;

/**
 * Admin-level page: point the plugin at an existing repository, browse its
 * archives, restore from them.
 *
 * Nothing here creates or fills a repository. That is the job of whatever
 * already backs this server up.
 */
final class AdminPage
{
    private const TABS = [
        'overview'   => 'Overview',
        'repository' => 'Repository',
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

    /** Set when a restore-a-user attempt named an account DirectAdmin does not have. */
    private ?string $missingAccount = null;

    public function __construct(
        private readonly Plugin $plugin,
        private readonly PluginRequest $request,
        private readonly CsrfTokenizer $csrf,
    ) {
        $this->flash = new FlashBag();
    }

    public function render(): string
    {
        if ($this->request->isPost()) {
            $this->handlePost();
        }

        // Reload: a POST may have changed the repository or the secrets.
        $config = $this->plugin->config()->load();
        $repository = $this->plugin->repository();
        $runner = $repository->runner();

        $tab = $this->request->param('tab', 'overview');
        if (!isset(self::TABS[$tab])) {
            $tab = 'overview';
        }

        $context = [
            'title'      => 'Borg Backup',
            'subtitle'   => 'Browse and restore from the BorgBackup repository this server already writes to.',
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
                'save_repository'      => $this->saveRepository(),
                'run_check'            => $this->startJob(Job::TYPE_CHECK),
                'build_index'          => $this->buildIndex(),
                'break_lock'           => $this->breakLock(),
                'restore'              => $this->restore(),
                'restore_domains'      => $this->restoreAccountTree('domains'),
                'restore_email'        => $this->restoreAccountTree('imap'),
                'restore_user'         => $this->restoreUser(),
                'restore_admin_backup' => $this->restoreAdminBackupOnly(),
                default                => $this->flash->error('Unknown action.'),
            };
        } catch (\Throwable $e) {
            $this->flash->error($e->getMessage());
        }
    }

    /**
     * Save the repository location, after proving something is actually there.
     *
     * The plugin never runs `borg init`: the repository belongs to the backup
     * job that already exists on this server, and creating a second, empty one
     * next to it -- which is what a typo in this field would otherwise do --
     * would look like a working configuration with nothing to restore from.
     * So the location is probed with `borg info` and only stored if borg
     * recognises it. That probe also settles the passphrase and BORG_RSH,
     * because an encrypted or remote repository cannot be read without them.
     */
    private function saveRepository(): void
    {
        $body = $this->request->body();

        // The secrets first: the probe below needs them, and a passphrase for a
        // repository that then fails to verify is still worth keeping, because
        // the usual fix is to correct the path and submit again.
        if ($this->request->bodyBool('clear_passphrase')) {
            $this->plugin->config()->setPassphrase('');
            $this->flash->warning('Stored passphrase removed.');
        } elseif ($body->getString('passphrase') !== '') {
            $this->plugin->config()->setPassphrase($body->getString('passphrase'));
        }

        $errors = $this->plugin->config()->save([
            'admin_backups_dir'    => $body->getString('admin_backups_dir'),
            'restore_admin_backup' => $this->request->bodyBool('restore_admin_backup'),
            'user_restore_enabled' => $this->request->bodyBool('user_restore_enabled'),
        ]);
        foreach ($errors as $error) {
            $this->flash->error($error);
        }

        $location = trim($body->getString('repository'));
        $sshCommand = $body->getString('ssh_command');

        // Validate without persisting, so a location that passes the syntax
        // rules but is not a repository never reaches the config file.
        $candidate = $this->plugin->config()->load()->withValues([
            'repository'  => $location,
            'ssh_command' => trim($sshCommand),
        ]);

        $syntax = $this->plugin->config()->validate([
            'repository'  => $location,
            'ssh_command' => $sshCommand,
        ]);
        if ($syntax !== []) {
            foreach ($syntax as $error) {
                $this->flash->error($error);
            }

            return;
        }

        if (!$this->plugin->repository()->runner()->isInstalled()) {
            $this->flash->error('borg is not installed on this server, so the repository cannot be verified.');

            return;
        }

        $probe = (new Repository($this->plugin->borg(), $candidate))->info();

        if (!$probe->isSuccessful()) {
            $this->flash->error($this->describeProbeFailure($probe->errorMessage(), $location));

            return;
        }

        $saved = $this->plugin->config()->save([
            'repository'  => $location,
            'ssh_command' => $sshCommand,
        ]);
        foreach ($saved as $error) {
            $this->flash->error($error);
        }

        if ($saved === []) {
            $encryption = $probe->json()['encryption']['mode'] ?? null;
            $this->flash->success(\sprintf(
                'Repository found and saved: %s%s.',
                $location,
                \is_string($encryption) ? ', encryption ' . $encryption : ''
            ));
        }
    }

    /**
     * Turn borg's error into something that names the likely cause.
     *
     * The two that matter are "there is no repository here" and "there is one
     * but I cannot open it", and borg's own wording for the first is easy to
     * read as a plugin bug rather than a wrong path.
     */
    private function describeProbeFailure(string $message, string $location): string
    {
        if (stripos($message, 'does not exist') !== false
            || stripos($message, 'is not a valid repository') !== false
            || stripos($message, 'no such file or directory') !== false
        ) {
            return \sprintf(
                'No borg repository at %s, so nothing was saved. This plugin only restores;'
                . ' it does not create repositories. Check the path against the one your backup'
                . ' script uses, and remember borg repositories are per-host (often .../borg/$(hostname)).',
                $location
            );
        }

        if (stripos($message, 'passphrase') !== false || stripos($message, 'decrypt') !== false) {
            return 'A repository is there but could not be unlocked: ' . $message
                . ' Save the passphrase in the field above and try again.';
        }

        return 'Could not read the repository, so nothing was saved: ' . $message;
    }

    private function startJob(string $type): void
    {
        if (!$this->ensureReady()) {
            return;
        }

        $job = $this->plugin->jobs()->create($type, $this->request->username, ['trigger' => 'manual']);
        $this->plugin->dispatcher()->dispatch($job);

        $this->flash->success(\sprintf('%s started. Follow it under Jobs.', ucfirst($type)));
    }

    /** Queue the one-off scan that makes an archive browsable. */
    private function buildIndex(): void
    {
        if (!$this->ensureReady()) {
            return;
        }

        $archive = $this->request->body()->getString('archive');
        if ($archive === '') {
            $this->flash->error('No archive selected.');

            return;
        }

        // The form's checkbox wins; the configured default applies when the
        // request did not come from that form.
        $files = $this->request->body()->has('index_files')
            ? $this->request->bodyBool('index_files')
            : $this->plugin->config()->load()->indexFiles();

        $job = $this->plugin->jobs()->create(
            Job::TYPE_INDEX,
            $this->request->username,
            ['archive' => $archive, 'files' => $files]
        );
        $this->plugin->dispatcher()->dispatch($job);

        $this->flash->success('Indexing started. Progress is shown below; this only has to happen once per archive.');
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

    private function restore(): void
    {
        if (!$this->ensureReady()) {
            return;
        }

        $archive = $this->request->body()->getString('archive');
        $paths = $this->request->bodyList('paths');
        $username = trim($this->request->body()->getString('username'));

        if ($archive === '' || $paths === []) {
            $this->flash->error('Select at least one item to restore.');

            return;
        }

        // Reached through an account, so the selection goes back where it came
        // from, like every other restore on this screen. Without an account --
        // which the UI no longer produces, but a hand-made request could -- the
        // old staging behaviour and its protected-destination list still apply.
        if ($username === '') {
            $destination = $this->restoreDestination();
            $this->queueRestore($archive, $paths, $destination);

            $this->flash->success(\sprintf(
                'Restoring %d item(s) into %s. Follow it under Jobs.',
                \count($paths),
                $destination
            ));

            return;
        }

        try {
            $account = Account::resolve($username, $this->plugin->paths);
        } catch (\Throwable $e) {
            $this->flash->error($e->getMessage());

            return;
        }

        $this->queueRestore($archive, $paths, '/', $username, [$account->home]);

        $this->flash->success(\sprintf(
            'Restoring %d item(s) back into %s. Follow it under Jobs.',
            \count($paths),
            $account->home
        ));
    }

    /**
     * Restore one of an account's two directories back over itself.
     *
     * These are the two requests that actually come in -- "the site is broken"
     * and "the mail is gone" -- so they are one button each rather than a path
     * to be typed. There is no destination field on purpose: restoring
     * /home/alice/domains anywhere but /home/alice/domains produces a copy
     * nobody asked for, which then has to be moved by hand with the right
     * ownership. In place is the only answer that finishes the job.
     *
     * The subtree is still validated against the account's home before the job
     * is queued, so "in place" cannot be talked into meaning somewhere else.
     */
    private function restoreAccountTree(string $subdirectory): void
    {
        if (!$this->ensureReady()) {
            return;
        }

        $archive = $this->request->body()->getString('archive');
        $username = trim($this->request->body()->getString('username'));

        if ($archive === '' || $username === '') {
            $this->flash->error('Choose an archive and an account.');

            return;
        }

        try {
            $account = Account::resolve($username, $this->plugin->paths);
        } catch (\Throwable $e) {
            $this->missingAccount = $username;
            $this->flash->error($e->getMessage()
                . ' Create the account in DirectAdmin first, or restore its DirectAdmin backup below and recreate it.');

            return;
        }

        $target = $account->home . '/' . $subdirectory;
        $label = $subdirectory === 'imap' ? 'Email' : 'Domains';

        // Deleting first is the malware case: a restore only adds and
        // overwrites, so a webshell dropped since the backup would survive one.
        // It is never implied -- the operator ticks it and types the username.
        $cleanPaths = [];
        if ($this->request->bodyBool('clean_first')) {
            if ($this->request->body()->getString('clean_confirm') !== $username) {
                $this->flash->error(\sprintf(
                    'Type "%s" to confirm deleting %s before restoring it.',
                    $username,
                    $target
                ));

                return;
            }

            $cleanPaths[] = $this->assertCleanable($target, $account->home);
        }

        $this->queueRestore(
            $archive,
            [$target],
            '/',
            $username,
            [$account->home],
            $cleanPaths,
            $account->home
        );

        $this->flash->success(\sprintf(
            'Restoring %s for %s back into %s%s. Follow it under Jobs.',
            $label,
            $username,
            $target,
            $cleanPaths === [] ? '' : ', deleting what is there first'
        ));
    }

    /**
     * Restore a whole user: their home directory plus the DirectAdmin admin
     * backup holding their configuration and databases.
     *
     * The account must already exist in DirectAdmin. Restoring a home directory
     * for an account DirectAdmin does not know about would leave orphaned files
     * with no owner, and this plugin deliberately does not create accounts —
     * DirectAdmin's own restore does that, from the very tarball this can
     * extract for you.
     */
    private function restoreUser(): void
    {
        if (!$this->ensureReady()) {
            return;
        }

        $config = $this->plugin->config()->load();
        $archive = $this->request->body()->getString('archive');
        $username = trim($this->request->body()->getString('username'));
        $destination = $this->restoreDestination();

        if ($archive === '' || $username === '') {
            $this->flash->error('Choose an archive and a username.');

            return;
        }

        $account = null;
        try {
            $account = Account::resolve($username, $this->plugin->paths);
        } catch (\Throwable $e) {
            // Deliberately not a fallback to /home/<username>: see the note
            // above. Tell the operator what to do and let them decide.
            $this->missingAccount = $username;
            $this->flash->error(\sprintf(
                '%s Create the user in DirectAdmin first, then restore their files. '
                . 'If the account is gone entirely, restore its DirectAdmin backup below and '
                . 'use Admin Level -> Restore Backups to recreate the account, then come back here.',
                $e->getMessage()
            ));

            return;
        }

        $paths = [$account->home];

        $adminBackup = $config->restoreAdminBackup()
            ? $this->findAdminBackup($archive, $config, $username)
            : null;

        if ($config->restoreAdminBackup() && $adminBackup === null) {
            $this->flash->warning(\sprintf(
                'No DirectAdmin backup for "%s" was found in %s in this archive, so only the home directory '
                . 'is being restored. Databases and account configuration live in that backup, not in the home directory.',
                $username,
                $config->adminBackupsDir()
            ));
        } elseif ($adminBackup !== null) {
            $paths[] = $adminBackup->path;
        }

        $inPlace = $this->request->bodyBool('in_place');
        $scopedRoots = $inPlace ? [$account->home, $config->adminBackupsDir()] : null;

        // Optional pre-clean, for the malware case: delete the site directory so
        // files the attacker added do not survive the restore. Only meaningful
        // in place, and only ever with the operator asking for it by name.
        $cleanPaths = [];
        if ($inPlace && $this->request->bodyBool('clean_first')) {
            $cleanDir = trim($this->request->body()->getString('clean_dir'));

            if ($cleanDir === '') {
                $this->flash->error('Name the directory to delete before restoring.');

                return;
            }
            if ($this->request->body()->getString('clean_confirm') !== $username) {
                $this->flash->error('Type the username to confirm deleting files before the restore.');

                return;
            }

            // Relative names are read against the account's home, so "domains"
            // means this user's domains and nothing else.
            $absolute = str_starts_with($cleanDir, '/')
                ? $cleanDir
                : $account->home . '/' . ltrim($cleanDir, '/');

            $cleanPaths[] = $this->assertCleanable($absolute, $account->home);
        }

        // The worker re-validates the deletion against this root rather than
        // trusting the paths in the job file.
        $this->queueRestore($archive, $paths, $destination, $username, $scopedRoots, $cleanPaths, $account->home);

        $contents = $adminBackup !== null
            ? 'home directory and ' . basename($adminBackup->path)
            : 'home directory only';

        $this->flash->success($inPlace
            ? \sprintf(
                'Restoring %s (%s) to its original location.%s Follow it under Jobs.',
                $username,
                $contents,
                $cleanPaths !== []
                    ? ' ' . implode(' and ', $cleanPaths) . ' will be deleted first, so nothing outside the archive survives.'
                    : ' Existing files are overwritten; files added since the backup are left alone.'
            )
            : \sprintf('Restoring %s (%s) into %s. Follow it under Jobs.', $username, $contents, $destination));
    }

    /**
     * Restore only a user's DirectAdmin backup tarball.
     *
     * This is the chicken-and-egg case: the account no longer exists, and the
     * tarball is exactly what DirectAdmin's own restore needs in order to
     * recreate it. Always an explicit action, never something that happens on
     * the operator's behalf.
     */
    private function restoreAdminBackupOnly(): void
    {
        if (!$this->ensureReady()) {
            return;
        }

        $config = $this->plugin->config()->load();
        $archive = $this->request->body()->getString('archive');
        $username = trim($this->request->body()->getString('username'));
        $destination = $this->restoreDestination();

        if ($archive === '' || !Account::isValidName($username)) {
            $this->flash->error('Choose an archive and a valid username.');

            return;
        }

        $adminBackup = $this->plugin->repository()->findAdminBackup($archive, $config->adminBackupsDir(), $username);

        if ($adminBackup === null) {
            $this->flash->error(\sprintf(
                'No DirectAdmin backup for "%s" was found in %s in this archive. Looked for %s.',
                $username,
                $config->adminBackupsDir(),
                implode(', ', array_map(
                    static fn (string $extension) => $username . '.' . $extension,
                    Repository::ADMIN_BACKUP_EXTENSIONS
                ))
            ));

            return;
        }

        // DirectAdmin's restore reads from its own backups directory, so putting
        // the tarball back where it came from is what makes it show up there.
        // borg extract runs as root and restores the original ownership, which
        // DirectAdmin also requires of those files.
        $toDirectAdmin = $this->request->bodyBool('to_directadmin');
        $scopedRoots = $toDirectAdmin ? [$config->adminBackupsDir()] : null;

        $this->queueRestore($archive, [$adminBackup->path], $destination, $username, $scopedRoots);

        $this->flash->success($toDirectAdmin
            ? \sprintf(
                'Restoring %s to %s, where DirectAdmin looks for it. Once it finishes, use '
                . 'Admin Level -> Restore Backups to recreate the account, then return here to restore the home directory.',
                basename($adminBackup->path),
                $config->adminBackupsDir()
            )
            : \sprintf(
                'Restoring %s into %s. Copy it to %s and chown it to admin before using '
                . 'Admin Level -> Restore Backups, then return here to restore the home directory.',
                basename($adminBackup->path),
                $destination,
                $config->adminBackupsDir()
            ));
    }

    /** Where a restore-a-user run writes, defaulting to a staging directory. */
    private function restoreDestination(): string
    {
        $destination = trim($this->request->body()->getString('destination'));

        return PathGuard::normalize($destination === '' ? '/home/admin/borg_restore' : $destination);
    }

    /**
     * A path the restore may delete first: inside the account's home, and never
     * the home itself. Mirrors the check the worker repeats before deleting.
     */
    private function assertCleanable(string $path, string $home): string
    {
        $normalized = PathGuard::confine($path, $home);

        if ($normalized === rtrim($home, '/')) {
            throw new BorgPluginException('Refusing to delete the whole home directory. Name a subdirectory such as "domains".');
        }

        return $normalized;
    }

    /**
     * Queue a restore.
     *
     * Normally everything is written below a staging directory, keeping its
     * full original path, and the protected-destination list stops an operator
     * dropping an old /etc over a running system.
     *
     * $scopedRoots switches that off deliberately: when every path being
     * restored is known to sit inside a named root — one account's home, or the
     * DirectAdmin backups directory — restoring to the original location is the
     * correct operation, not an accident. The roots are checked here rather
     * than trusted from the caller.
     *
     * @param string[]      $paths
     * @param string[]|null $scopedRoots non-null means restore in place
     * @param string[]      $cleanPaths  directories to delete before extracting
     */
    private function queueRestore(
        string $archive,
        array $paths,
        string $destination,
        ?string $username = null,
        ?array $scopedRoots = null,
        array $cleanPaths = [],
        ?string $cleanRoot = null,
    ): Job {
        $paths = array_map([PathGuard::class, 'normalize'], $paths);

        if ($scopedRoots !== null) {
            foreach ($paths as $path) {
                $within = false;
                foreach ($scopedRoots as $root) {
                    if (PathGuard::isWithin($path, $root)) {
                        $within = true;
                        break;
                    }
                }
                if (!$within) {
                    throw new BorgPluginException(\sprintf('Refusing to restore %s in place: it is outside %s.', $path, implode(' and ', $scopedRoots)));
                }
            }

            // borg strips the leading slash and writes relative to the working
            // directory, so "/" is what puts a file back where it came from.
            $destination = '/';
        } elseif (\in_array($destination, self::PROTECTED_DESTINATIONS, true)) {
            throw new BorgPluginException(\sprintf('Refusing to restore directly into %s. Restore into a staging directory and move files from there.', $destination));
        }

        $job = $this->plugin->jobs()->create(Job::TYPE_RESTORE, $this->request->username, array_filter([
            'archive'      => $archive,
            'paths'        => $paths,
            'destination'  => $destination,
            'restore_user' => $username,
            'in_place'     => $scopedRoots !== null ? true : null,
            'clean_paths'  => $cleanPaths !== [] ? $cleanPaths : null,
            'clean_root'   => $cleanPaths !== [] ? $cleanRoot : null,
            'trigger'      => 'manual',
        ], static fn ($value) => $value !== null));

        $this->plugin->dispatcher()->dispatch($job);

        return $job;
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

    /**
     * The one probe both Overview and Repository need.
     *
     * Everything shown about the repository comes from borg rather than from
     * the config file: the encryption mode, the size and the date of the newest
     * archive are facts about a repository this plugin does not own, so caching
     * them locally would only let them go stale.
     *
     * @return array<string,mixed>
     */
    private function probeRepository(Configuration $config): array
    {
        $detected = [
            'reachable'     => false,
            'id'            => null,
            'short_id'      => null,
            'encryption'    => null,
            'archive_count' => null,
            'unique_size'   => null,
            'total_size'    => null,
            'last_archive'  => null,
            'error'         => null,
        ];

        if (!$config->isConfigured() || !$this->plugin->repository()->runner()->isInstalled()) {
            return $detected;
        }

        $info = $this->plugin->repository()->info();

        if (!$info->isSuccessful()) {
            $detected['error'] = $info->errorMessage();

            return $detected;
        }

        $json = $info->json();
        $stats = $json['cache']['stats'] ?? null;

        $detected['reachable'] = true;
        $detected['id'] = $json['repository']['id'] ?? null;
        // Shortened here rather than with Twig's |slice: plugin scripts run on
        // `php -n`, where neither mbstring nor iconv is loaded and that filter
        // dies with "Call to undefined function iconv_substr()".
        $detected['short_id'] = \is_string($detected['id']) ? substr($detected['id'], 0, 12) : null;
        $detected['encryption'] = $json['encryption']['mode'] ?? null;

        if (\is_array($stats)) {
            $detected['unique_size'] = $stats['unique_csize'] ?? null;
            $detected['total_size'] = $stats['total_size'] ?? null;
        }

        // The newest archive is what "is this repository still being written
        // to?" actually means here, and it is the only honest answer available:
        // the runs that produce it are not this plugin's, so there is no job
        // history to read it off.
        $archives = $this->plugin->repository()->listArchives()['archives'];
        $detected['archive_count'] = \count($archives);
        $detected['last_archive'] = $archives === [] ? null : $archives[0]->time;

        return $detected;
    }

    /** @return array<string,mixed> */
    private function overviewContext(Configuration $config): array
    {
        return ['detected' => $this->probeRepository($config)];
    }

    /** @return array<string,mixed> */
    private function repositoryContext(Configuration $config): array
    {
        return [
            'detected'         => $this->probeRepository($config),
            'has_passphrase'   => $config->hasPassphrase(),
            'user_restore_dir' => $config->userRestoreDir(),
            'passphrase_file'  => $this->plugin->paths->passphraseFile(),
            'config_file'      => $this->plugin->paths->configFile(),
        ];
    }

    /** @return array<string,mixed> */
    private function archivesContext(Configuration $config): array
    {
        if (!$config->isConfigured()) {
            return ['archives' => [], 'browser' => null, 'error' => null];
        }

        $archive = $this->request->param('archive');
        if ($archive !== '') {
            $index = $this->plugin->archiveIndex();

            // Nothing to show until the archive has been scanned.
            if (!$index->exists($archive)) {
                return [
                    'archives'   => [],
                    'error'      => null,
                    'browser'    => $this->browserContext($archive),
                    'accounts'   => null,
                    'user_panel' => null,
                ];
            }

            $user = trim((string) $this->request->param('user'));

            // The account name becomes a path -- /home/<user> -- so it has to
            // look like an account before it is used as one. Without this,
            // ?user=../../etc would browse /etc while the breadcrumbs claimed
            // to be inside a customer's home.
            if ($user !== '' && !Account::isValidName($user)) {
                $this->flash->error('Invalid account name.');
                $user = '';
            }

            // An archive opens on its accounts, not on its filesystem root. The
            // root of a DirectAdmin backup is `home` and `etc` -- two entries,
            // neither of which is what anyone came here for -- and getting from
            // there to a customer is four clicks through directories that only
            // ever have one interesting child.
            if ($user === '') {
                return [
                    'archives'   => [],
                    'error'      => null,
                    'browser'    => null,
                    'accounts'   => $this->accountsContext($archive),
                    'user_panel' => null,
                ];
            }

            return [
                'archives'   => [],
                'error'      => null,
                'accounts'   => null,
                'user_panel' => $this->userPanelContext($archive, $user, $config),
                // The file browser is still there for the odd single-file
                // request, but you reach it through an account rather than by
                // walking down from the root.
                'browser' => $this->request->param('path') === ''
                    ? null
                    : $this->browserContext($archive, $user),
            ];
        }

        $listing = $this->plugin->repository()->listArchives();
        $index = $this->plugin->archiveIndex();

        // Indexes for archives the server's own prune has since removed are
        // dead weight, and this is the one place the current list is known.
        if ($listing['result']->isSuccessful()) {
            $index->purge(...array_map(static fn (Archive $a) => $a->name, $listing['archives']));
        }

        $format = $this->rememberArchiveFormat($config, $listing['archives']);

        return [
            'browser'      => null,
            'user_restore' => null,
            'error'        => $listing['result']->isSuccessful() ? null : $listing['result']->errorMessage(),
            'archives'     => array_map(
                static fn (Archive $a) => [
                    'name'     => $a->name,
                    'stale'    => $index->isStale($a->name, $config->adminBackupsDir(), $config->indexFiles()),
                    'built_at' => $index->builtAt($a->name),
                    // The instant each row is rendered from: the archive's own
                    // name when it carries a date, since that is what the
                    // backup called this run, and borg's recorded time when it
                    // does not.
                    'taken_at' => ArchiveName::date($a->name, $format) ?? $a->time,
                    'indexed'  => $index->exists($a->name),
                ],
                $listing['archives']
            ),
        ];
    }

    /**
     * Work out the archive naming template and keep it.
     *
     * Detected rather than configured, because the template belongs to the
     * backup script and asking an operator to retype it here is asking for the
     * two to drift. It is stored so the reading stays stable, and re-detected
     * whenever the names stop matching -- which is what happens when the backup
     * script's template is changed.
     *
     * @param Archive[] $archives
     */
    private function rememberArchiveFormat(Configuration $config, array $archives): string
    {
        $newest = $archives[0] ?? null;
        if ($newest === null) {
            return $config->archiveDateFormat();
        }

        $detected = ArchiveName::format($newest->name);
        if ($detected === null || $detected === $config->archiveDateFormat()) {
            return $config->archiveDateFormat();
        }

        // Only on a real change, so rendering this page is not a write.
        $this->plugin->config()->save(['archive_date_format' => $detected]);

        return $detected;
    }

    /**
     * @return array<string,mixed>
     */
    /**
     * The accounts in an archive, read from /home.
     *
     * Which accounts DirectAdmin still has is shown alongside, because the two
     * disagreeing is the interesting case: an account in the archive but not on
     * the server is one that was deleted, and that is a different recovery --
     * DirectAdmin has to recreate it from its own backup before a home
     * directory means anything.
     *
     * @return array<string,mixed>
     */
    private function accountsContext(string $archive): array
    {
        $listing = $this->plugin->archiveIndex()->listDirectory($archive, '/home');

        $accounts = [];
        foreach ($listing->entries as $entry) {
            if (!$entry->isDirectory()) {
                continue;
            }

            $accounts[] = [
                'name'    => $entry->name,
                'exists'  => is_dir($this->plugin->paths->daUsersDir . '/' . $entry->name),
                'indexed' => true,
            ];
        }

        return [
            'archive'   => $archive,
            'accounts'  => $accounts,
            'truncated' => $listing->truncated,
            'readable'  => $listing->readable,
        ];
    }

    /**
     * Everything offered for one account in one archive.
     *
     * @return array<string,mixed>
     */
    private function userPanelContext(string $archive, string $username, Configuration $config): array
    {
        $home = '/home/' . $username;
        $index = $this->plugin->archiveIndex();

        $present = [];
        foreach ($index->listDirectory($archive, $home)->entries as $entry) {
            if ($entry->isDirectory()) {
                $present[$entry->name] = true;
            }
        }

        $adminBackup = $config->restoreAdminBackup()
            ? $this->findAdminBackup($archive, $config, $username)
            : null;

        return [
            'archive'           => $archive,
            'index_stale'       => $index->isStale($archive, $config->adminBackupsDir(), $config->indexFiles()),
            'username'          => $username,
            'home'              => $home,
            'exists'            => is_dir($this->plugin->paths->daUsersDir . '/' . $username),
            'has_domains'       => isset($present['domains']),
            'has_email'         => isset($present['imap']),
            'domains_path'      => $home . '/domains',
            'email_path'        => $home . '/imap',
            'admin_backup'      => $adminBackup?->path,
            'admin_backups_dir' => $config->adminBackupsDir(),
            'missing_account'   => $this->missingAccount,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function browserContext(string $archive, string $username = ''): array
    {
        $home = $username === '' ? '' : '/home/' . $username;
        $path = $this->request->param('path', $home === '' ? '/' : $home);

        try {
            $path = PathGuard::normalize($path);
            // Reached through an account, so it stays inside that account. This
            // is convenience rather than a security boundary -- an admin may
            // browse anywhere -- but it keeps the breadcrumbs honest and stops
            // a stale link dropping you back at the archive root.
            if ($home !== '') {
                $path = PathGuard::confine($path, $home);
            }
        } catch (\Throwable) {
            $path = $home === '' ? '/' : $home;
        }

        $index = $this->plugin->archiveIndex();

        // Browsing goes through the index, never straight to borg. borg cannot
        // list one directory -- it walks the whole archive whatever you ask --
        // so a page that shelled out per click would cost a full scan each
        // time, and asking for the root would try to buffer every entry.
        if (!$index->exists($archive)) {
            $running = $this->runningIndexJob($archive);

            return [
                'archive'             => $archive,
                'path'                => $path,
                'needs_index'         => true,
                'has_files'           => false,
                'index_default_files' => $this->plugin->config()->load()->indexFiles(),
                'index_job'           => $running === null ? null : $this->jobToArray($running),
                'index_log'           => $running === null ? '' : $this->plugin->jobs()->tail($running, 400),
                'index_status_url'    => $running === null
                    ? ''
                    : $this->request->baseUrl() . '/status.raw?job=' . rawurlencode($running->id),
                'crumbs'              => [],
                'entries'             => [],
                'truncated'           => false,
                'error'               => null,
                'default_destination' => '/home/admin/borg_restore',
            ];
        }

        $listing = $index->listDirectory($archive, $path);

        return [
            'archive'             => $archive,
            'path'                => $path,
            'needs_index'         => false,
            'has_files'           => $index->includesFiles($archive),
            'index_default_files' => false,
            'index_job'           => null,
            'index_log'           => '',
            'index_status_url'    => '',
            'username'            => $username,
            'crumbs'              => $this->crumbs($archive, $path, $username),
            'entries'             => array_map([$this, 'entryToArray'], $listing->entries),
            'truncated'           => $listing->truncated,
            'error'               => $listing->readable ? null : $listing->error,
            'default_destination' => '/home/admin/borg_restore',
        ];
    }

    /** The index job for this archive that is still running, if there is one. */
    /**
     * Locate an account's DirectAdmin backup inside an archive.
     *
     * Through the index when there is one, because asking borg costs a full
     * archive scan -- twenty seconds on a real server, paid every time the
     * restore screen is opened. The index records this directory's files even
     * when it is otherwise directories-only, precisely so this stays cheap.
     */
    private function findAdminBackup(string $archive, Configuration $config, string $username): ?ArchiveEntry
    {
        $index = $this->plugin->archiveIndex();

        if ($index->exists($archive)) {
            return Repository::pickAdminBackup(
                $index->listDirectory($archive, $config->adminBackupsDir())->entries,
                $username
            );
        }

        return $this->plugin->repository()->findAdminBackup($archive, $config->adminBackupsDir(), $username);
    }

    private function runningIndexJob(string $archive): ?Job
    {
        foreach ($this->plugin->jobs()->recent(20, null, Job::TYPE_INDEX) as $job) {
            if (($job->params()['archive'] ?? null) === $archive && !$job->isFinished()) {
                return $job;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
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
    /** @return array<int,array{label:string,url:string}> */
    private function crumbs(string $archive, string $path, string $username = ''): array
    {
        $link = fn (array $extra): string => $this->request->url(array_filter(
            ['tab' => 'archives', 'archive' => $archive, 'user' => $username] + $extra,
            static fn ($value) => $value !== '' && $value !== null
        ));

        // The trail starts at the account, not at the filesystem root: the
        // levels above it are /home and / , which are not places to go.
        $home = $username === '' ? '' : '/home/' . $username;

        if ($home === '') {
            $crumbs = [['label' => '/', 'url' => $link(['path' => '/'])]];
            $accumulated = '';
            $segments = array_filter(explode('/', $path));
        } else {
            $crumbs = [['label' => $username, 'url' => $link(['path' => $home])]];
            $accumulated = $home;
            $relative = ltrim(substr($path, \strlen($home)), '/');
            $segments = $relative === '' ? [] : array_filter(explode('/', $relative));
        }

        foreach ($segments as $segment) {
            $accumulated .= '/' . $segment;
            $crumbs[] = ['label' => $segment, 'url' => $link(['path' => $accumulated])];
        }

        return $crumbs;
    }

    /** @return array<string,mixed> */
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

    /** @return array<string,mixed> */
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
            'running'     => $job->isRunning(),
        ];
    }
}
