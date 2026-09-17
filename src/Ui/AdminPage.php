<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Ui;

use Recranet\DirectAdminBorg\Borg\Archive;
use Recranet\DirectAdminBorg\Borg\ArchiveEntry;
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
                'break_lock'           => $this->breakLock(),
                'restore'              => $this->restore(),
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
            'user_restore_dir'     => $body->getString('user_restore_dir'),
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

        if ($archive === '' || $paths === []) {
            $this->flash->error('Select at least one item to restore.');

            return;
        }
        if (trim($this->request->body()->getString('destination')) === '') {
            $this->flash->error('A restore destination is required.');

            return;
        }

        $destination = $this->restoreDestination();
        $this->queueRestore($archive, $paths, $destination);

        $this->flash->success(\sprintf(
            'Restoring %d item(s) into %s. Follow it under Jobs.',
            \count($paths),
            $destination
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
            ? $this->plugin->repository()->findAdminBackup($archive, $config->adminBackupsDir(), $username)
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

        if ($archive === '' || !$this->isPlausibleUsername($username)) {
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

    private function isPlausibleUsername(string $username): bool
    {
        return (bool) preg_match('/^[a-z_][a-z0-9_-]{0,31}$/i', $username);
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
            'detected'        => $this->probeRepository($config),
            'has_passphrase'  => $config->hasPassphrase(),
            'passphrase_file' => $this->plugin->paths->passphraseFile(),
            'config_file'     => $this->plugin->paths->configFile(),
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
            return [
                'archives'     => [],
                'error'        => null,
                'browser'      => $this->browserContext($archive),
                'user_restore' => $this->userRestoreContext($archive, $config),
            ];
        }

        $listing = $this->plugin->repository()->listArchives();

        return [
            'browser'      => null,
            'user_restore' => null,
            'error'        => $listing['result']->isSuccessful() ? null : $listing['result']->errorMessage(),
            'archives'     => array_map(
                static fn (Archive $a) => ['name' => $a->name, 'time' => $a->time],
                $listing['archives']
            ),
        ];
    }

    /** @return array<string,mixed> */
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

    /**
     * State for the "restore a whole user" panel.
     *
     * $missingAccount is set when the operator just tried to restore a user
     * DirectAdmin does not have, which is what turns the admin-backup-only
     * button on.
     */
    /** @return array<string,mixed> */
    private function userRestoreContext(string $archive, Configuration $config): array
    {
        return [
            'archive'             => $archive,
            'admin_backups_dir'   => $config->adminBackupsDir(),
            'enabled'             => $config->restoreAdminBackup(),
            'missing_account'     => $this->missingAccount,
            'username'            => trim($this->request->body()->getString('username')),
            'default_destination' => '/home/admin/borg_restore',
        ];
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
