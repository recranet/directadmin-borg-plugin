<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Test;

use Recranet\DirectAdminBorg\Borg\BorgRunner;
use Recranet\DirectAdminBorg\Paths;
use Recranet\DirectAdminBorg\Security\Account;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Answers the question this plugin had to settle before it could be designed:
 * can borg run as the unprivileged `admin` account instead of root, perhaps
 * with borg and admin sharing a group?
 *
 * Rather than asserting the answer from documentation, this runs the same
 * backup twice — once as root, once as admin — against identical source data
 * and compares what actually lands in each archive.
 */
final class PrivilegeProbe
{
    public function __construct(
        private readonly Harness $harness,
        private readonly Paths $paths,
    ) {
    }

    public function run(): void
    {
        $filesystem = new Filesystem();
        $borg = BorgRunner::locateBinary();

        $adminRepo = '/backup/probe-admin';
        $rootRepo = '/backup/probe-root';

        $adminHome = '/tmp/borg-admin-home';
        $filesystem->remove([$adminRepo, $rootRepo, $adminHome]);

        $admin = Account::resolve('admin', $this->paths);

        // borg needs a writable HOME for its cache and config.
        $filesystem->mkdir($adminHome, 0700);
        $filesystem->chown($adminHome, $admin->uid);
        $filesystem->chgrp($adminHome, $admin->gid);

        // Somewhere both accounts can create a repository.
        $filesystem->mkdir('/backup', 0777);
        $filesystem->chmod('/backup', 0777);

        $asAdmin = fn (array $args): array => $this->harness->exec(
            array_merge(
                ['/usr/bin/setpriv', '--reuid', (string) $admin->uid, '--regid', (string) $admin->gid, '--clear-groups', $borg],
                $args
            ),
            ['HOME' => $adminHome, 'BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK' => 'yes']
        );

        $asRoot = fn (array $args): array => $this->harness->exec(
            array_merge([$borg], $args),
            ['HOME' => '/root', 'BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK' => 'yes']
        );

        // 1. Can the admin account even execute the borg binary?
        $this->harness->ok(
            $asAdmin(['--version'])['exit'] === 0,
            'the admin account CAN execute the borg binary (so a shared group changes nothing)'
        );

        // 2. Can it own a repository?
        $this->harness->ok(
            $asAdmin(['init', '--encryption=none', $adminRepo])['exit'] === 0,
            'admin CAN create and own a repository'
        );

        // 3. What does a backup taken as admin actually contain?
        $adminCreate = $asAdmin(['create', $adminRepo . '::probe', '/home']);
        $adminPaths = $this->pathsIn($asAdmin(['list', '--format', '{path}{NL}', $adminRepo . '::probe'])['stdout']);

        // 4. The same backup as root.
        $this->harness->ok($asRoot(['init', '--encryption=none', $rootRepo])['exit'] === 0, 'root CAN create a repository');
        $asRoot(['create', $rootRepo . '::probe', '/home']);
        $rootPaths = $this->pathsIn($asRoot(['list', '--format', '{path}{NL}', $rootRepo . '::probe'])['stdout']);

        // The verdict.
        $secret = 'home/alice/.my.cnf';
        $site = 'home/alice/domains/example.com/public_html/index.html';

        $this->harness->ok(\in_array($site, $rootPaths, true), 'as root: website files ARE backed up');
        $this->harness->ok(\in_array($secret, $rootPaths, true), 'as root: private files ARE backed up');
        $this->harness->notOk(\in_array($site, $adminPaths, true), 'as admin: website files are NOT backed up');
        $this->harness->notOk(\in_array($secret, $adminPaths, true), 'as admin: private files are NOT backed up');

        $this->harness->ok(
            \count($adminPaths) < \count($rootPaths),
            \sprintf('admin archive is incomplete: %d entries vs %d as root', \count($adminPaths), \count($rootPaths))
        );

        // The dangerous part: borg treats unreadable files as a warning, so the
        // backup "succeeds" while containing almost nothing.
        $this->harness->ok(
            stripos($adminCreate['stderr'], 'permission denied') !== false,
            'borg as admin reports "Permission denied" but still exits as a warning (silent data loss)'
        );
        $this->harness->ok(
            $adminCreate['exit'] === 1,
            'borg as admin exits 1 (warning), not a hard failure a cron job would notice'
        );

        echo "\n  \033[1mVerdict:\033[0m borg must run as root.\n";
        echo "  The admin account can execute the binary and own a repository, so putting\n";
        echo "  borg and admin in a shared group solves nothing — the binary is already\n";
        echo "  world-executable. What admin cannot do is READ /home/<user> (mode 0711),\n";
        echo "  /etc/shadow, or the DirectAdmin config. A backup taken as admin exits with\n";
        echo "  a warning rather than an error and silently omits customer data.\n";
        echo "  Hence plugin.conf: admin_run_as=root and user_run_as=root.\n";
    }

    /** @return string[] */
    private function pathsIn(string $output): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $output))));
    }
}
