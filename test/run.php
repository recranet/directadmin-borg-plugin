<?php

/**
 * Test suite for the DirectAdmin Borg plugin.
 *
 * Runs inside the test container (see test/docker/) against a real borg and a
 * real /home with DirectAdmin-style 0711 permissions. It drives the plugin the
 * way DirectAdmin does — environment in, stdout out — so the entry points,
 * shebangs and env decoding are covered, not just the classes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/lib/Harness.php';
require_once __DIR__ . '/lib/PrivilegeProbe.php';

use Recranet\DirectAdminBorg\Borg\BorgRunner;
use Recranet\DirectAdminBorg\Borg\Repository;
use Recranet\DirectAdminBorg\Config\Configuration;
use Recranet\DirectAdminBorg\Http\PluginRequest;
use Recranet\DirectAdminBorg\Job\Job;
use Recranet\DirectAdminBorg\Job\JobRunner;
use Recranet\DirectAdminBorg\Security\Account;
use Recranet\DirectAdminBorg\Security\CsrfTokenizer;
use Recranet\DirectAdminBorg\Security\PathGuard;
use Recranet\DirectAdminBorg\Support\Format;
use Recranet\DirectAdminBorg\Test\Harness;
use Recranet\DirectAdminBorg\Test\PrivilegeProbe;

$pluginDir = dirname(__DIR__);
$t = new Harness($pluginDir);
$plugin = $t->reset();

$runJob = static function (Job $job) use ($plugin): int {
    return (new JobRunner($plugin, $plugin->jobs()))->run($job);
};

// This plugin never creates a repository and never writes an archive: that is
// the job of whatever already backs the server up. So the suite plays that part
// itself, shelling out to borg directly, and the plugin is only ever pointed at
// the result. These two helpers are deliberately outside the code under test —
// if a change makes the plugin start writing archives, these stop being the
// only thing that does, and the archive counts below fail.
$externalInit = static function (string $repository, string $encryption = 'none', string $passphrase = '', string $rsh = '') use ($plugin) {
    return $plugin->borg()
        ->withEnvironment(['BORG_PASSPHRASE' => $passphrase, 'BORG_RSH' => $rsh])
        ->run(['init', '--encryption=' . $encryption, $repository], 120);
};

$externalArchive = static function (string $repository, string $name, array $paths = ['/home', '/etc/passwd'], string $passphrase = '', string $rsh = '') use ($plugin) {
    return $plugin->borg()
        ->withEnvironment(['BORG_PASSPHRASE' => $passphrase, 'BORG_RSH' => $rsh])
        ->run(array_merge(['create', '--compression', 'lz4', $repository . '::' . $name], $paths), BorgRunner::NO_TIMEOUT);
};

// ============================================================== path handling

$t->group('Path normalisation');

$t->is(PathGuard::normalize('/home/alice/./docs/'), '/home/alice/docs', 'collapses "." and a trailing slash');
$t->is(PathGuard::normalize('/home/alice/docs/../files'), '/home/alice/files', 'resolves ".."');
$t->is(PathGuard::normalize('//home///alice'), '/home/alice', 'collapses repeated slashes');
$t->throws(static fn () => PathGuard::normalize('home/alice'), 'rejects a relative path');
$t->throws(static fn () => PathGuard::normalize('/../etc/shadow'), 'rejects escaping the filesystem root');
$t->throws(static fn () => PathGuard::normalize("/home/alice\0/x"), 'rejects a NUL byte');
$t->is(PathGuard::toArchiveMember('/home/alice'), 'home/alice', 'strips the leading slash for archive members');

$t->ok(PathGuard::isWithin('/home/bob/x', '/home/bob'), 'a child is within its root');
$t->ok(PathGuard::isWithin('/home/bob', '/home/bob'), 'a root is within itself');
$t->notOk(PathGuard::isWithin('/home/bobby', '/home/bob'), 'a sibling sharing a prefix is NOT within the root');
$t->notOk(PathGuard::isWithin('/home/bo', '/home/bob'), 'a shorter path is not within the root');

// ============================================================== account rules

$t->group('Account confinement');

$alice = Account::resolve('alice', $plugin->paths);
$t->is($alice->home, '/home/alice', 'resolves the home directory from /etc/passwd');
$t->is($alice->confine('/home/alice/domains'), '/home/alice/domains', 'allows its own subdirectory');
$t->is($alice->confine('/home/alice/a/../b'), '/home/alice/b', 'normalises before checking');
$t->throws(static fn () => $alice->confine('/home/bob/secret.txt'), 'blocks another customer\'s home');
$t->throws(static fn () => $alice->confine('/etc/shadow'), 'blocks a system path');
$t->throws(static fn () => $alice->confine('/home/alice/../bob'), 'blocks traversal out of the home');
$t->throws(static fn () => $alice->confine('/home/alice/../../etc/passwd'), 'blocks deep traversal');
$t->throws(static fn () => $alice->confine('/home/alicex'), 'blocks a sibling with a shared prefix');
$t->throws(static fn () => Account::resolve('root', $plugin->paths), 'refuses a uid 0 account');
$t->throws(static fn () => Account::resolve('../../etc/passwd', $plugin->paths), 'rejects a malformed account name');
$t->throws(static fn () => Account::resolve('nosuchuser', $plugin->paths), 'rejects an unknown account');

// ============================================================== configuration

$t->group('Configuration validation');

$config = $plugin->config();

$t->notEmpty($config->save(['repository' => 'not a repo']), 'rejects a nonsense repository');
$t->notEmpty($config->save(['repository' => '/backup/repo::archive']), 'rejects an archive name in the repository');
$t->notEmpty($config->save(['repository' => "/backup/repo\nrm -rf /"]), 'rejects a newline in the repository');
$t->notEmpty($config->save(['repository' => '']), 'rejects an empty repository');
$t->isEmpty($config->save(['repository' => '/backup/test-repo']), 'accepts an absolute path');
$t->isEmpty($config->save(['repository' => 'ssh://borg@host:22/srv/repo']), 'accepts an ssh:// URL');
$t->isEmpty($config->save(['repository' => 'borg@host:/srv/repo']), 'accepts scp-style syntax');

$t->notEmpty($config->save(['ssh_command' => "ssh -i k\nid"]), 'rejects a newline in BORG_RSH');
$t->isEmpty($config->save(['ssh_command' => 'ssh -i /root/.ssh/borg -o StrictHostKeyChecking=yes']), 'accepts a normal ssh command');

$t->notEmpty($config->save(['user_restore_dir' => '../../etc']), 'rejects traversal in the restore directory');
$t->notEmpty($config->save(['user_restore_dir' => 'a/b']), 'rejects a nested restore directory');
$t->notEmpty($config->save(['user_restore_dir' => '..']), 'rejects ".." as the restore directory');
$t->isEmpty($config->save(['user_restore_dir' => 'borg_restore']), 'accepts a plain restore directory name');

// The settings a backup would need are not merely unused now, they are gone:
// a stale config.json from 1.x must not quietly resurrect them.
foreach (['source_paths', 'compression', 'schedule_enabled', 'keep_daily', 'encryption'] as $retired) {
    $t->notOk(array_key_exists($retired, Configuration::DEFAULTS), 'the backup-era setting "' . $retired . '" is gone');
}

// validate() is what the repository form uses to reject input before paying for
// a borg probe, so it has to agree with save() without writing anything.
$t->notEmpty($config->validate(['repository' => 'nonsense']), 'validate() rejects what save() rejects');
$t->isEmpty($config->validate(['repository' => '/backup/somewhere']), 'validate() accepts what save() accepts');
$t->notOk($config->load()->repository() === '/backup/somewhere', 'validate() does not persist anything');

// A rejected save must leave the stored configuration untouched.
$config->save(['repository' => '/backup/test-repo']);
$config->save(['repository' => 'garbage value']);
$t->is($config->load()->repository(), '/backup/test-repo', 'a rejected save does not modify stored config');

$t->group('Configuration hygiene');

$config->save(['repository' => '  /backup/test-repo  ']);
$t->is($config->load()->repository(), '/backup/test-repo', 'the repository location is trimmed');
$t->ok(count(Configuration::DEFAULTS) > 0, 'defaults are defined');
$t->is($config->load()->get('nonexistent_key'), null, 'unknown keys are not readable');

// ======================================================================= CSRF

$t->group('CSRF tokens');

$csrf = new CsrfTokenizer($plugin->paths, $plugin->filesystem());
$token = $csrf->token(PluginRequest::LEVEL_ADMIN, 'admin');

$t->is(strlen($token), 64, 'token is a sha256 hex digest');
$t->ok($csrf->isValid(PluginRequest::LEVEL_ADMIN, 'admin', $token), 'accepts its own token');
$t->notOk($csrf->isValid(PluginRequest::LEVEL_ADMIN, 'admin', 'forged'), 'rejects a forged token');
$t->notOk($csrf->isValid(PluginRequest::LEVEL_ADMIN, 'admin', null), 'rejects a missing token');
$t->notOk($csrf->isValid(PluginRequest::LEVEL_USER, 'admin', $token), 'an admin token is not valid at user level');
$t->notOk($csrf->isValid(PluginRequest::LEVEL_ADMIN, 'alice', $token), 'one account\'s token is not valid for another');

// ================================================================== decoding

$t->group('Request decoding');

$decoded = $t->request(PluginRequest::LEVEL_ADMIN, 'admin', ['path' => '/home/alice/a b&c']);
$t->is($decoded->param('path'), '/home/alice/a b&c', 'decodes an entity-encoded query string');

$posted = $t->request(PluginRequest::LEVEL_ADMIN, 'admin', [], ['paths' => ['/home/alice/x', '/home/alice/y']], 'POST');
$t->is(count($posted->bodyList('paths')), 2, 'decodes repeated POST fields into a list');
$t->ok($posted->isPost(), 'detects a POST');
$t->is($posted->bodyList('missing'), [], 'a missing list field is empty, not an error');

// =================================================================== borg CLI

$t->group('borg availability');

$runner = new BorgRunner(BorgRunner::locateBinary(), '/root');
$t->ok($runner->isInstalled(), 'borg is installed: ' . ($runner->version() ?? 'n/a'));
$t->notOk($runner->isUnsupportedMajor(), 'borg major version is supported (1.x)');
// Which pruning flag is correct depends on the borg present, so the suite
// follows the same feature detection the plugin does rather than pinning a
// version. AlmaLinux 8's EPEL still carries 1.1.
$modernBorg = $runner->supportsGlobArchives();
$t->ok(
    $modernBorg === version_compare((string) $runner->version(), '1.2.0', '>='),
    'glob-archives support matches the installed borg (' . $runner->version() . ')'
);

$t->group('Pointing the plugin at an existing repository');

$plugin = $t->reset();
$plugin->filesystem()->mkdir('/backup', 0700);

// The server's own backup, standing in for /root/utils/backup.sh or whatever
// else fills the repository on a real machine.
$init = $externalInit('/backup/test-repo');
$t->ok($init->isSuccessful(), 'the external backup creates its repository' . ($init->isSuccessful() ? '' : ': ' . $init->errorMessage()));

$created = $externalArchive('/backup/test-repo', 'test-1');
$t->ok($created->isSuccessful() || $created->isWarning(), 'the external backup writes an archive: ' . $created->errorMessage());

$plugin->config()->save(['repository' => '/backup/test-repo']);
$plugin->config()->setPassphrase('');

$t->group('Reading what is there');

$info = $plugin->repository()->info();
$t->ok($info->isSuccessful(), 'the plugin can read a repository it did not create');
$t->is($info->json()['encryption']['mode'] ?? null, 'none', 'the encryption mode is read from the repository, not from config');
$t->notOk(method_exists($plugin->repository(), 'initialize'), 'there is no way to initialise a repository');
$t->notOk(method_exists($plugin->repository(), 'deleteArchive'), 'there is no way to delete an archive');
$t->notOk(method_exists($plugin->repository(), 'createArguments'), 'there is no way to create an archive');
$t->notOk(method_exists($plugin->repository(), 'pruneArguments'), 'there is no way to prune');
$t->notOk(in_array('backup', Job::TYPES, true), 'there is no backup job type');
$t->notOk(in_array('prune', Job::TYPES, true), 'there is no prune job type');

$listing = $plugin->repository()->listArchives();
$t->ok($listing['result']->isSuccessful(), 'archives can be listed');
$t->is(count($listing['archives']), 1, 'the externally written archive is visible');
$archive = $listing['archives'][0]->name;

$t->group('Archive contents');

$subtree = $plugin->repository()->listSubtree($archive, '/home/alice');
$paths = array_map(static fn (array $row) => '/' . ltrim((string) $row['path'], '/'), $subtree['rows']);

$t->ok(in_array('/home/alice/.my.cnf', $paths, true), 'a root-only file IS in the archive');
$t->ok(in_array('/home/alice/.secret/notes.txt', $paths, true), 'a mode-0600 file IS in the archive');
$t->ok(in_array('/home/alice/domains/example.com/public_html/index.html', $paths, true), 'website content IS in the archive');

$directory = $plugin->repository()->listDirectory($archive, '/home/alice');
$names = array_map(static fn ($entry) => $entry->name, $directory->entries);

$t->ok(in_array('domains', $names, true), 'directory listing includes subdirectories');
$t->notOk(in_array('index.html', $names, true), 'directory listing is one level deep only');
$t->ok($directory->entries[0]->isDirectory(), 'directories sort before files');
$t->notOk($directory->truncated, 'a small directory is not reported as truncated');

// ==================================================================== restore

$t->group('User restore');

$restore = $plugin->jobs()->create(Job::TYPE_RESTORE, 'alice', [
    'archive'     => $archive,
    'paths'       => ['/home/alice/domains'],
    'destination' => '/home/alice/borg_restore',
    'chown_to'    => 'alice',
]);
$exit = $runJob($restore);
$restore = $plugin->jobs()->find($restore->id);

$t->is($exit, 0, 'the restore job exits cleanly');
$t->is($restore->status(), Job::STATUS_SUCCESS, 'restore succeeds: ' . $restore->message());

$restored = '/home/alice/borg_restore/home/alice/domains/example.com/public_html/index.html';
$t->ok(is_file($restored), 'the restored file exists at the expected path');
$t->is(trim((string) @file_get_contents($restored)), '<h1>alice site</h1>', 'restored content matches the original');

$aliceUid = Account::resolve('alice', $plugin->paths)->uid;
$t->is((int) stat('/home/alice/borg_restore')['uid'], $aliceUid, 'the restored tree is owned by the user, not root');
$t->is((int) stat($restored)['uid'], $aliceUid, 'the restored file is owned by the user');
$t->is(
    (int) stat('/home/alice/borg_restore/home/alice/domains')['uid'],
    $aliceUid,
    'ownership is applied recursively, not just at the top'
);

$t->group('Restore confinement (worker)');

$crossAccount = $plugin->jobs()->create(Job::TYPE_RESTORE, 'alice', [
    'archive'     => $archive,
    'paths'       => ['/home/bob'],
    'destination' => '/home/alice/borg_restore',
    'chown_to'    => 'alice',
]);
$runJob($crossAccount);
$crossAccount = $plugin->jobs()->find($crossAccount->id);

$t->is($crossAccount->status(), Job::STATUS_FAILED, 'the worker refuses to extract another account\'s path');
$t->notOk(is_dir('/home/alice/borg_restore/home/bob'), 'nothing belonging to bob was written');

$escape = $plugin->jobs()->create(Job::TYPE_RESTORE, 'alice', [
    'archive'     => $archive,
    'paths'       => ['/home/alice/domains'],
    'destination' => '/tmp/pwned',
    'chown_to'    => 'alice',
]);
$runJob($escape);
$escape = $plugin->jobs()->find($escape->id);

$t->is($escape->status(), Job::STATUS_FAILED, 'the worker refuses a destination outside the home');
$t->notOk(is_dir('/tmp/pwned'), 'nothing was written outside the home');

// ============================================================ page rendering

$t->group('User page (rendered through the real entry point)');

$userToken = (new CsrfTokenizer($plugin->paths, $plugin->filesystem()))->token(PluginRequest::LEVEL_USER, 'alice');

$out = $t->page('user', 'alice');
$t->contains($out, 'Available backups', 'the archive list renders');
$t->notContains($out, 'Fatal error', 'no PHP errors leak into the page');
$t->notContains($out, '/home/bob', 'no other account appears on the page');

$out = $t->page('user', 'alice', ['archive' => $archive, 'path' => '/home/alice']);
$t->contains($out, 'domains', 'the browser lists the account\'s own directories');

$out = $t->page('user', 'alice', ['archive' => $archive, 'path' => '/home/bob']);
$t->notContains($out, 'secret.txt', 'browsing another home silently falls back');
$t->notContains($out, 'bob.example', 'no content from another account leaks');

$out = $t->page('user', 'alice', ['archive' => $archive, 'path' => '/etc']);
$t->notContains($out, 'passwd', 'browsing /etc is impossible at user level');

$out = $t->page('user', 'alice', ['archive' => 'no-such-archive']);
$t->contains($out, 'Unknown archive', 'an unknown archive name is rejected');

$t->group('User restore requests');

$out = $t->page('user', 'alice', [], [
    'action'     => 'restore',
    'archive'    => $archive,
    'paths'      => ['/home/bob/secret.txt'],
    'csrf_token' => $userToken,
], 'POST');
$t->contains($out, 'outside the permitted directory', 'the page rejects another account\'s path');
$t->notContains($out, 'Restoring 1 item', 'no restore job was started');

$out = $t->page('user', 'alice', [], [
    'action'  => 'restore',
    'archive' => $archive,
    'paths'   => ['/home/alice/domains'],
], 'POST');
$t->contains($out, 'Security token', 'a POST without a CSRF token is rejected');

$out = $t->page('user', 'alice', [], [
    'action'     => 'restore',
    'archive'    => 'no-such-archive',
    'paths'      => ['/home/alice/domains'],
    'csrf_token' => $userToken,
], 'POST');
$t->contains($out, 'Unknown archive', 'a restore from an unknown archive is rejected');

$t->group('Escaping');

// A crafted archive name must not be able to inject markup into the page.
$t->notContains($t->page('user', 'alice', ['archive' => '<script>alert(1)</script>']), '<script>alert(1)</script>', 'archive names are escaped');
$t->notContains($t->page('user', 'alice', ['path' => '"><script>x</script>']), '"><script>x</script>', 'paths are escaped');

$t->group('Admin page');

$out = $t->page('admin');
$t->contains($out, 'Borg Backup', 'the admin page renders');
$t->contains($out, 'Repository', 'tabs render');
$t->notContains($out, 'Fatal error', 'no PHP errors leak into the page');
$t->notContains($out, 'not root', 'no root warning is shown when running as root');

$t->contains($t->page('admin', 'admin', ['tab' => 'archives']), $archive, 'the admin archive list shows the archive');

$out = $t->page('admin', 'admin', ['tab' => 'archives', 'archive' => $archive, 'path' => '/home']);
$t->contains($out, 'alice', 'an admin can browse every account');
$t->contains($out, 'bob', 'admin browsing is deliberately unconfined');

$repoTab = $t->page('admin', 'admin', ['tab' => 'repository']);
$t->contains($repoTab, 'Existing repository', 'the repository tab renders');
$t->contains($repoTab, 'Repository found', 'it reports the repository it detected');
$t->notContains($repoTab, 'Initialise', 'there is no way to initialise a repository from the UI');
$t->contains($t->page('admin', 'admin', ['tab' => 'jobs']), 'Recent jobs', 'the jobs tab renders');

foreach (['overview', 'repository', 'archives', 'jobs'] as $adminTab) {
    $t->notContains(
        $t->page('admin', 'admin', ['tab' => $adminTab]),
        'Borg plugin error',
        'the ' . $adminTab . ' tab renders without an entry-point error'
    );
}

// The Backup tab is gone, and an unknown tab falls back rather than erroring.
$t->notContains($t->page('admin'), '>Backup<', 'there is no Backup tab');
$t->contains($t->page('admin', 'admin', ['tab' => 'backup']), 'Borg Backup', 'the retired tab name falls back to Overview');

$overview = $t->page('admin');
$t->contains($overview, 'Newest archive', 'the overview reports the newest archive it found');
$t->notContains($overview, 'Back up now', 'the overview offers no way to start a backup');
$t->notContains($overview, 'Prune', 'the overview offers no way to prune');

$t->group('Admin restore guards');

$adminToken = (new CsrfTokenizer($plugin->paths, $plugin->filesystem()))->token(PluginRequest::LEVEL_ADMIN, 'admin');

foreach (['/', '/etc', '/usr', '/home'] as $protected) {
    $out = $t->page('admin', 'admin', [], [
        'action'      => 'restore',
        'archive'     => $archive,
        'paths'       => ['/home/alice'],
        'destination' => $protected,
        'csrf_token'  => $adminToken,
    ], 'POST');
    $t->contains($out, 'Refusing to restore directly into', 'admin cannot restore straight onto ' . $protected);
}

// ================================================================ status API

$t->group('Status endpoint authorisation');

$status = $t->invoke('user', 'status.raw', 'alice', ['job' => $restore->id]);
$t->contains($status['stdout'], '"status"', 'a user can poll their own job');
$t->contains($status['stdout'], '200 OK', 'the raw response carries an HTTP status line');

$status = $t->invoke('user', 'status.raw', 'bob', ['job' => $restore->id]);
$t->contains($status['stdout'], '404', 'another user cannot poll that job');
$t->notContains($status['stdout'], '"log"', 'no log content leaks to another user');

$status = $t->invoke('admin', 'status.raw', 'admin', ['job' => $restore->id]);
$t->contains($status['stdout'], '"log"', 'an admin can poll any job');

$t->group('Menu endpoints');

$menu = $t->invoke('user', 'menu.raw', 'alice');
$t->contains($menu['stdout'], 'Restore Files', 'the user menu entry is present when restores are enabled');

$plugin->config()->save(['user_restore_enabled' => false]);
$menu = $t->invoke('user', 'menu.raw', 'alice');
$t->contains($menu['stdout'], '[]', 'the user menu entry disappears when restores are disabled');
$t->contains($t->page('user', 'alice'), 'turned off', 'the user page says restores are off');
$plugin->config()->save(['user_restore_enabled' => true]);

$menu = $t->invoke('admin', 'menu.raw');
$t->contains($menu['stdout'], 'Borg Backup', 'the admin menu entry is present');

// ====================================================== DirectAdmin backups

$t->group('Admin backups configuration');

$t->is($plugin->config()->load()->adminBackupsDir(), '/home/admin/admin_backups', 'defaults to DirectAdmin\'s own location');
$t->notEmpty($plugin->config()->save(['admin_backups_dir' => 'relative/path']), 'rejects a relative admin backups path');
$t->notEmpty($plugin->config()->save(['admin_backups_dir' => '']), 'rejects an empty admin backups path');
$t->notEmpty($plugin->config()->save(['admin_backups_dir' => "/home/admin\nrm -rf /"]), 'rejects a newline in the path');
$t->isEmpty($plugin->config()->save(['admin_backups_dir' => '/home/admin/admin_backups/']), 'accepts an absolute path');
$t->is($plugin->config()->load()->adminBackupsDir(), '/home/admin/admin_backups', 'a trailing slash is normalised away');

$t->group('Finding a user\'s DirectAdmin backup in an archive');

$adminDir = $plugin->config()->load()->adminBackupsDir();

$aliceBackup = $plugin->repository()->findAdminBackup($archive, $adminDir, 'alice');
$t->ok($aliceBackup !== null, 'finds a .tar.zst backup');
$t->is($aliceBackup?->path, '/home/admin/admin_backups/alice.tar.zst', 'returns the full archive path');

$bobBackup = $plugin->repository()->findAdminBackup($archive, $adminDir, 'bob');
$t->ok($bobBackup !== null, 'finds a .tar.gz backup');
$t->is($bobBackup?->path, '/home/admin/admin_backups/bob.tar.gz', 'matches whichever compression was used');

$t->ok($plugin->repository()->findAdminBackup($archive, $adminDir, 'nobody') === null, 'returns nothing for an unknown user');
$t->ok($plugin->repository()->findAdminBackup($archive, '/home/admin/wrong', 'alice') === null, 'returns nothing when the directory is wrong');
// A username must not be able to reach a neighbouring file by partial match.
$t->ok($plugin->repository()->findAdminBackup($archive, $adminDir, 'alic') === null, 'does not match on a partial username');

$t->group('Restoring a whole user');

$restoreUser = static function (string $username, string $destination = '/home/admin/borg_restore') use ($t, $archive, $plugin) {
    return $t->page('admin', 'admin', ['tab' => 'archives', 'archive' => $archive], [
        'action'      => 'restore_user',
        'archive'     => $archive,
        'username'    => $username,
        'destination' => $destination,
        'csrf_token'  => (new CsrfTokenizer($plugin->paths, $plugin->filesystem()))->token(PluginRequest::LEVEL_ADMIN, 'admin'),
    ], 'POST');
};

$out = $restoreUser('alice');
$t->contains($out, 'Restoring alice', 'a restore-a-user run starts for an existing account');
$t->contains($out, 'alice.tar.zst', 'the DirectAdmin backup is included alongside the home directory');

$latest = $plugin->jobs()->recent(1)[0];
$t->is($latest->type(), Job::TYPE_RESTORE, 'it queues a restore job');
$t->is($latest->params()['paths'], ['/home/alice', '/home/admin/admin_backups/alice.tar.zst'], 'the job restores both the home and the DirectAdmin backup');
$t->is($latest->params()['restore_user'] ?? null, 'alice', 'the job records which user it is for');

$t->ok($t->waitForJob($plugin, $latest->id, 180)?->status() === Job::STATUS_SUCCESS, 'the user restore completes');
$t->ok(is_file('/home/admin/borg_restore/home/admin/admin_backups/alice.tar.zst'), 'the DirectAdmin backup lands on disk');
$t->ok(is_dir('/home/admin/borg_restore/home/alice/domains'), 'the home directory lands on disk');

$t->group('Restoring a user DirectAdmin does not have');

$out = $restoreUser('ghost');
$t->contains($out, 'Create the user in DirectAdmin first', 'a missing account is a prompt, not a guess');
$t->contains($out, 'Restore Backups', 'the prompt names the DirectAdmin screen that recreates it');
$t->notContains($out, 'Restoring ghost', 'no home-directory restore is started for a missing account');
$t->notOk(is_dir('/home/admin/borg_restore/home/ghost'), 'nothing was restored into a home for a non-existent account');
$t->contains($out, 'Restore ghost', 'the admin-backup-only action is offered');
$t->contains($out, 'Restore Backups', 'the numbered recovery sequence is shown');

// That action is explicit: it only happens when the operator asks for it.
$out = $t->page('admin', 'admin', ['tab' => 'archives', 'archive' => $archive], [
    'action'      => 'restore_admin_backup',
    'archive'     => $archive,
    'username'    => 'ghost',
    'destination' => '/home/admin/borg_restore',
    'csrf_token'  => (new CsrfTokenizer($plugin->paths, $plugin->filesystem()))->token(PluginRequest::LEVEL_ADMIN, 'admin'),
], 'POST');
$t->contains($out, 'ghost.tar.gz', 'the DirectAdmin backup alone can be restored');
$t->contains($out, 'Restore Backups', 'the next step is spelled out');

$ghostJob = $plugin->jobs()->recent(1)[0];
$t->is($ghostJob->params()['paths'], ['/home/admin/admin_backups/ghost.tar.gz'], 'only the tarball is restored, not a home directory');
$t->ok($t->waitForJob($plugin, $ghostJob->id, 180)?->status() === Job::STATUS_SUCCESS, 'the tarball restore completes');
$t->ok(is_file('/home/admin/borg_restore/home/admin/admin_backups/ghost.tar.gz'), 'the tarball lands on disk');
$t->notOk(is_dir('/home/admin/borg_restore/home/ghost'), 'still no home directory for the missing account');

$t->group('Handing the tarball straight to DirectAdmin');

// The realistic recovery case: the account is gone, and DirectAdmin's restore
// only looks in its own backups directory.
$plugin->filesystem()->remove('/home/admin/admin_backups/ghost.tar.gz');
$t->notOk(is_file('/home/admin/admin_backups/ghost.tar.gz'), 'the tarball is gone from the live server');

$out = $t->page('admin', 'admin', ['tab' => 'archives', 'archive' => $archive], [
    'action'         => 'restore_admin_backup',
    'archive'        => $archive,
    'username'       => 'ghost',
    'to_directadmin' => '1',
    'csrf_token'     => (new CsrfTokenizer($plugin->paths, $plugin->filesystem()))->token(PluginRequest::LEVEL_ADMIN, 'admin'),
], 'POST');
$t->contains($out, 'where DirectAdmin looks for it', 'the message says where it is going');

$daJob = $plugin->jobs()->recent(1)[0];
$t->is($daJob->params()['destination'], '/', 'an in-place restore extracts relative to /');
$t->is($daJob->params()['in_place'] ?? null, true, 'the job records that it restores in place');
$t->ok($t->waitForJob($plugin, $daJob->id, 180)?->status() === Job::STATUS_SUCCESS, 'the restore completes');

$t->ok(is_file('/home/admin/admin_backups/ghost.tar.gz'), 'the tarball is back where DirectAdmin reads it');
$t->is(
    trim((string) @file_get_contents('/home/admin/admin_backups/ghost.tar.gz')),
    'a user that no longer exists',
    'its contents are intact'
);

// DirectAdmin requires these files to belong to admin, and borg extract as root
// restores the original ownership rather than leaving them owned by root.
$adminUid = Account::resolve('admin', $plugin->paths)->uid;
$t->is((int) stat('/home/admin/admin_backups/ghost.tar.gz')['uid'], $adminUid, 'ownership is restored to admin, as DirectAdmin requires');

$t->group('Restoring a home directory in place');

$live = '/home/alice/domains/example.com/public_html/index.html';
@file_put_contents($live, '<h1>broken</h1>');
@file_put_contents('/home/alice/added-after-the-backup.txt', 'newer than the archive');

$out = $t->page('admin', 'admin', ['tab' => 'archives', 'archive' => $archive], [
    'action'     => 'restore_user',
    'archive'    => $archive,
    'username'   => 'alice',
    'in_place'   => '1',
    'csrf_token' => (new CsrfTokenizer($plugin->paths, $plugin->filesystem()))->token(PluginRequest::LEVEL_ADMIN, 'admin'),
], 'POST');
$t->contains($out, 'to its original location', 'an in-place user restore is offered');
$t->contains($out, 'files added since the backup are left alone', 'the overlay behaviour is stated plainly');

$inPlace = $plugin->jobs()->recent(1)[0];
$t->is($inPlace->params()['destination'], '/', 'the in-place user restore extracts relative to /');
$t->ok($t->waitForJob($plugin, $inPlace->id, 180)?->status() === Job::STATUS_SUCCESS, 'the in-place restore completes');

$t->is(trim((string) @file_get_contents($live)), '<h1>alice site</h1>', 'the live file is restored over the damaged one');
$t->ok(is_file('/home/alice/added-after-the-backup.txt'), 'a file created after the backup survives: a restore is an overlay, not a mirror');
$t->is((int) stat($live)['uid'], $aliceUid, 'the restored live file still belongs to the account');

$t->group('Pre-clean before restoring (malware cleanup)');

$adminTokenFor = static fn () => (new CsrfTokenizer($plugin->paths, $plugin->filesystem()))
    ->token(PluginRequest::LEVEL_ADMIN, 'admin');

$restoreClean = static function (array $extra) use ($t, $archive, $adminTokenFor) {
    return $t->page('admin', 'admin', ['tab' => 'archives', 'archive' => $archive], array_merge([
        'action'     => 'restore_user',
        'archive'    => $archive,
        'username'   => 'alice',
        'in_place'   => '1',
        'csrf_token' => $adminTokenFor(),
    ], $extra), 'POST');
};

// The real shape of the problem: public_html is a symlink into a repository
// checkout beside it, so deleting public_html alone leaves the actual files.
$siteDir = '/home/alice/domains/example.com';
@mkdir($siteDir . '/repo/wp-content', 0755, true);
@file_put_contents($siteDir . '/repo/shell.php', '<?php /* webshell */');
@file_put_contents($siteDir . '/repo/index.php', 'clean');
$plugin->filesystem()->remove($siteDir . '/public_html');
@symlink($siteDir . '/repo', $siteDir . '/public_html');
$t->ok(is_link($siteDir . '/public_html'), 'public_html is a symlink into the repo checkout, as on a real deploy');

// Deleting only public_html would unlink the symlink and leave the webshell.
$plugin->filesystem()->remove($siteDir . '/public_html');
$t->ok(is_file($siteDir . '/repo/shell.php'), 'deleting public_html alone leaves the webshell behind: the symlink goes, the files stay');
@symlink($siteDir . '/repo', $siteDir . '/public_html');

$out = $restoreClean(['clean_first' => '1', 'clean_dir' => 'domains', 'clean_confirm' => 'alice']);
$t->contains($out, 'will be deleted first', 'the pre-clean is announced');
$t->contains($out, '/home/alice/domains', 'the message names exactly what is deleted');

$cleanJob = $plugin->jobs()->recent(1)[0];
$t->is($cleanJob->params()['clean_paths'], ['/home/alice/domains'], 'the job records the directory to delete');
$t->ok($t->waitForJob($plugin, $cleanJob->id, 180)?->status() === Job::STATUS_SUCCESS, 'the clean restore completes');

$t->notOk(is_file($siteDir . '/repo/shell.php'), 'the webshell is gone: deleting the domains directory reaches the symlink target too');
$t->notOk(is_dir($siteDir . '/repo'), 'the repository checkout beside public_html is gone');
$t->ok(is_file('/home/alice/domains/example.com/public_html/index.html'), 'the archived site is restored in its place');
$t->is((int) stat('/home/alice/domains')['uid'], $aliceUid, 'the rebuilt tree belongs to the account');

$t->group('Pre-clean never follows a symlink out of the home');

// The dangerous case: a symlink inside the deleted tree pointing outside it.
// Removing the link must not touch what it points at.
@mkdir('/home/alice/domains/evil.com', 0755, true);
@symlink('/etc', '/home/alice/domains/evil.com/escape');
@file_put_contents('/etc/borg-canary.txt', 'must survive');
$t->ok(is_link('/home/alice/domains/evil.com/escape'), 'a symlink to /etc exists inside the tree to be deleted');

$restoreClean(['clean_first' => '1', 'clean_dir' => 'domains', 'clean_confirm' => 'alice']);
$escapeJob = $plugin->jobs()->recent(1)[0];
$t->ok($t->waitForJob($plugin, $escapeJob->id, 180)?->status() === Job::STATUS_SUCCESS, 'the restore completes');

$t->notOk(is_link('/home/alice/domains/evil.com/escape'), 'the symlink itself is removed');
$t->ok(is_file('/etc/borg-canary.txt'), 'the symlink target is untouched: deletion does not follow links out of the home');
$t->ok(is_file('/etc/passwd'), '/etc is intact');
@unlink('/etc/borg-canary.txt');

$t->group('Pre-clean guards');

$t->contains(
    $restoreClean(['clean_first' => '1', 'clean_dir' => 'domains', 'clean_confirm' => 'wrong']),
    'Type the username to confirm',
    'a wrong confirmation blocks the deletion'
);
$t->contains(
    $restoreClean(['clean_first' => '1', 'clean_dir' => '', 'clean_confirm' => 'alice']),
    'Name the directory to delete',
    'an empty directory is rejected'
);
$t->contains(
    $restoreClean(['clean_first' => '1', 'clean_dir' => '/etc', 'clean_confirm' => 'alice']),
    'outside the permitted directory',
    'a directory outside the home is rejected'
);
$t->contains(
    $restoreClean(['clean_first' => '1', 'clean_dir' => '../bob', 'clean_confirm' => 'alice']),
    'outside the permitted directory',
    'traversal into another account is rejected'
);
$t->contains(
    $restoreClean(['clean_first' => '1', 'clean_dir' => '.', 'clean_confirm' => 'alice']),
    'Refusing to delete the whole home directory',
    'deleting the home itself is refused'
);
$t->contains(
    $restoreClean(['clean_first' => '1', 'clean_dir' => '/home/alice', 'clean_confirm' => 'alice']),
    'Refusing to delete the whole home directory',
    'naming the home by its absolute path is also refused'
);
$t->ok(is_file('/home/alice/.my.cnf'), 'none of the rejected attempts deleted anything');

// A pre-clean only makes sense in place; without it the staging copy is
// untouched and nothing is deleted.
$out = $restoreClean(['in_place' => '0', 'clean_first' => '1', 'clean_dir' => 'domains', 'clean_confirm' => 'alice']);
$t->notContains($out, 'will be deleted first', 'a staging restore never deletes anything');
$t->ok(is_dir('/home/alice/domains'), 'the live site survives a staging restore');

$t->group('In-place restores stay scoped');

// The scope is checked when the job is queued, not trusted from the request.
$adminDirForScope = $plugin->config()->load()->adminBackupsDir();
$t->ok(
    !PathGuard::isWithin('/etc/shadow', '/home/alice') && !PathGuard::isWithin('/etc/shadow', $adminDirForScope),
    'a system path is outside every in-place root'
);

$out = $t->page('admin', 'admin', ['tab' => 'archives', 'archive' => $archive], [
    'action'      => 'restore',
    'archive'     => $archive,
    'paths'       => ['/etc/passwd'],
    'destination' => '/',
    'csrf_token'  => (new CsrfTokenizer($plugin->paths, $plugin->filesystem()))->token(PluginRequest::LEVEL_ADMIN, 'admin'),
], 'POST');
$t->contains($out, 'Refusing to restore directly into', 'the free-form restore still cannot target / in place');

$t->group('Restore-a-user guards');

$t->contains($restoreUser('alice', '/'), 'Refusing to restore directly into', 'protected destinations still apply');
$t->contains($restoreUser(''), 'Choose an archive and a username', 'a blank username is rejected');
$t->contains($restoreUser('../../etc'), 'Invalid account name', 'a traversal attempt in the username is rejected');

// A user with no DirectAdmin backup in this archive still gets their home back,
// with a warning that the databases are not included.
$out = $restoreUser('admin');
$t->contains($out, 'No DirectAdmin backup for &quot;admin&quot;', 'a missing tarball is called out (and the username is escaped)');
$t->contains($out, 'Databases and account configuration', 'the warning says what is missing');

$t->group('User level cannot reach admin backups');

$t->notContains($t->page('user', 'alice', ['archive' => $archive, 'path' => '/home/admin/admin_backups']), 'alice.tar.zst', 'a customer cannot browse the admin backups');
$out = $t->page('user', 'alice', [], [
    'action'     => 'restore',
    'archive'    => $archive,
    'paths'      => ['/home/admin/admin_backups/alice.tar.zst'],
    'csrf_token' => (new CsrfTokenizer($plugin->paths, $plugin->filesystem()))->token(PluginRequest::LEVEL_USER, 'alice'),
], 'POST');
$t->contains($out, 'outside the permitted directory', 'a customer cannot restore their own admin backup');

// ======================================================================= lock

$t->group('Repository locking');

$lock = $plugin->lockFactory()->createLock('borg-repository', 60.0, false);
$t->ok($lock->acquire(), 'the repository lock can be acquired');

$blocked = $plugin->jobs()->create(Job::TYPE_CHECK, 'test', []);
$runJob($blocked);
$blocked = $plugin->jobs()->find($blocked->id);
$t->is($blocked->status(), Job::STATUS_FAILED, 'a second exclusive job is refused while the lock is held');
$t->contains($blocked->message(), 'already running', 'the refusal explains why');

$lock->release();

$afterRelease = $plugin->jobs()->create(Job::TYPE_CHECK, 'test', []);
$runJob($afterRelease);
$afterRelease = $plugin->jobs()->find($afterRelease->id);
$t->ok($afterRelease->isFinished() && $afterRelease->status() !== Job::STATUS_FAILED, 'a check runs once the lock is released');

// A restore must stay possible while the server's own backup, or a check, has
// the repository busy -- that is exactly when someone needs to restore.
$lock->acquire();
$concurrentRestore = $plugin->jobs()->create(Job::TYPE_RESTORE, 'alice', [
    'archive'     => $archive,
    'paths'       => ['/home/alice/domains'],
    'destination' => '/home/alice/borg_restore',
    'chown_to'    => 'alice',
]);
$runJob($concurrentRestore);
$concurrentRestore = $plugin->jobs()->find($concurrentRestore->id);
$t->notOk(
    str_contains($concurrentRestore->message(), 'already running'),
    'a restore is not blocked by the writer lock'
);
$lock->release();

// =================================================================== console

$t->group('Console application');

$t->contains($t->console(['list'])['stdout'], 'borg:status', 'commands are registered');
$t->contains($t->console(['borg:status'])['stdout'], 'DirectAdmin Borg plugin', 'borg:status runs');
$t->contains($t->console(['borg:status'])['stdout'], '/backup/test-repo', 'borg:status reports the repository');
$t->is($t->console(['borg:job', 'not-a-job-id'])['exit'], 2, 'borg:job rejects a malformed id');
// Second-resolution ids would order arbitrarily within a second, and the job
// list is ordered by filename.
$t->is($t->console(['borg:job', '20260101-120000-backup-aabbccdd'])['exit'], 2, 'borg:job rejects the old second-resolution id format');

$queued = $plugin->jobs()->create(Job::TYPE_CHECK, 'test', []);
$plugin->jobs()->update($queued, ['status' => Job::STATUS_SUCCESS]);
$t->is($t->console(['borg:job', $queued->id])['exit'], 2, 'borg:job refuses to re-run a finished job');

$t->group('Job ordering');

$ordering = [];
for ($i = 0; $i < 5; ++$i) {
    $ordering[] = $plugin->jobs()->create(Job::TYPE_CHECK, 'ordering-test', [])->id;
}
$t->ok(count(array_unique($ordering)) === 5, 'ids created in a tight loop are unique');

$sorted = $ordering;
rsort($sorted, \SORT_STRING);
$t->is($sorted, array_reverse($ordering), 'ids sort by creation order even within the same second');

$listed = array_map(
    static fn (Job $job) => $job->id,
    $plugin->jobs()->recent(5, 'ordering-test')
);
$t->is($listed, array_reverse($ordering), 'the job list returns newest first, in true creation order');

$t->group('Detached dispatch');

// The real path a UI-triggered backup takes: a process that must outlive the
// request that started it.
$detached = $plugin->jobs()->create(Job::TYPE_CHECK, 'test', []);
$plugin->dispatcher()->dispatch($detached);
$detached = $t->waitForJob($plugin, $detached->id, 180);

$t->ok($detached !== null && $detached->isFinished(), 'a detached job runs to completion after its parent exits');
$t->ok(
    $detached !== null && in_array($detached->status(), [Job::STATUS_SUCCESS, Job::STATUS_WARNING], true),
    'the detached backup succeeded (' . ($detached?->status() ?? 'missing') . ')'
);

// ==================================================================== secrets

$t->group('Secrets are not leaked');

$plugin->config()->setPassphrase('super-secret-passphrase');

$t->notContains($t->page('admin', 'admin', ['tab' => 'repository']), 'super-secret-passphrase', 'the passphrase is never rendered');
$t->notContains($t->console(['borg:status'])['stdout'], 'super-secret-passphrase', 'the passphrase is not printed by borg:status');
$t->is(substr(sprintf('%o', fileperms($plugin->paths->passphraseFile())), -4), '0600', 'the passphrase file is 0600');
$t->is(substr(sprintf('%o', fileperms($plugin->paths->dataDir)), -4), '0700', 'the state directory is 0700');
$t->is(substr(sprintf('%o', fileperms($plugin->paths->configFile())), -4), '0600', 'the config file is 0600');
$t->is(substr(sprintf('%o', fileperms($plugin->paths->secretFile())), -4), '0600', 'the CSRF key is 0600');

$plugin->config()->setPassphrase('');

// ==================================================================== format

$t->group('Formatting helpers');

$t->is(Format::bytes(0), '0 B', 'formats zero bytes');
$t->is(Format::bytes(1536), '1.5 KiB', 'formats kibibytes');
$t->is(Format::bytes(null), '-', 'formats a missing size');
$t->is(Format::dateTime(''), '-', 'formats a missing timestamp');
$t->is(Format::age(''), 'never', 'formats a missing age');
$t->is(Format::currentUid(), 0, 'the suite runs as root, like the plugin does');

// ======================================================= admin POST actions

$t->group('Admin actions, driven through the form');

$post = static function (array $body, array $query = []) use ($t, $plugin) {
    return $t->page('admin', 'admin', $query, array_merge([
        'csrf_token' => (new CsrfTokenizer($plugin->paths, $plugin->filesystem()))->token(PluginRequest::LEVEL_ADMIN, 'admin'),
    ], $body), 'POST');
};

// Every action is reachable only by its exact name, so a typo in a form would
// otherwise ship green.
$t->contains($post(['action' => 'no_such_action']), 'Unknown action', 'an unrecognised action is rejected');

$out = $post([
    'action'      => 'save_repository',
    'repository'  => '/backup/test-repo',
    'ssh_command' => '',
]);
$t->contains($out, 'Repository found and saved', 'save_repository verifies and stores an existing repository');
$t->contains($out, 'encryption none', 'the confirmation reports what borg actually found');
$t->is($plugin->config()->load()->repository(), '/backup/test-repo', 'the repository was written');

$t->contains(
    $post(['action' => 'save_repository', 'repository' => 'nonsense value']),
    'must be an absolute path',
    'save_repository surfaces validation errors'
);

// The whole point of the feature: a path that is syntactically fine but holds
// no repository is refused, and the stored location is left alone. Saving it
// would produce a plugin that looks configured and has nothing to restore.
$t->group('A location with no repository in it is refused');

$out = $post(['action' => 'save_repository', 'repository' => '/backup/not-a-repo']);
$t->contains($out, 'No borg repository at /backup/not-a-repo', 'an empty path is refused by name');
$t->contains($out, 'does not create repositories', 'the refusal says the plugin will not create one');
$t->is($plugin->config()->load()->repository(), '/backup/test-repo', 'the previously saved repository is untouched');
$t->notOk(is_dir('/backup/not-a-repo'), 'nothing was created at the rejected path');

// A directory that exists but is not a repository is the likelier typo: the
// parent of the real one, say, or last year's.
$plugin->filesystem()->mkdir('/backup/empty-dir', 0700);
$out = $post(['action' => 'save_repository', 'repository' => '/backup/empty-dir']);
$t->contains($out, 'No borg repository', 'an existing directory that is not a repository is refused');
$t->is($plugin->config()->load()->repository(), '/backup/test-repo', 'the stored repository is still untouched');
$t->is(count(scandir('/backup/empty-dir') ?: []), 2, 'the rejected directory was not initialised behind our back');

$t->group('Restore settings, driven through the form');

$out = $post([
    'action'               => 'save_repository',
    'repository'           => '/backup/test-repo',
    'admin_backups_dir'    => '/home/admin/admin_backups',
    'user_restore_dir'     => 'borg_restore',
    'restore_admin_backup' => '1',
    'user_restore_enabled' => '1',
]);
$t->contains($out, 'Repository found and saved', 'the restore settings save alongside the repository');
$t->is($plugin->config()->load()->userRestoreDir(), 'borg_restore', 'the restore directory was written');
$t->ok($plugin->config()->load()->userRestoreEnabled(), 'a ticked checkbox saves as on');

// Unticked checkboxes are absent from a form post, which must read as false.
$post([
    'action'            => 'save_repository',
    'repository'        => '/backup/test-repo',
    'admin_backups_dir' => '/home/admin/admin_backups',
    'user_restore_dir'  => 'borg_restore',
]);
$t->notOk($plugin->config()->load()->userRestoreEnabled(), 'an unticked checkbox saves as off');
$post([
    'action'               => 'save_repository',
    'repository'           => '/backup/test-repo',
    'admin_backups_dir'    => '/home/admin/admin_backups',
    'user_restore_dir'     => 'borg_restore',
    'restore_admin_backup' => '1',
    'user_restore_enabled' => '1',
]);

$t->group('The backup actions are gone, not hidden');

foreach (['init_repository', 'save_backup', 'run_backup', 'run_prune', 'delete_archive'] as $retired) {
    $t->contains(
        $post(['action' => $retired, 'archive' => $archive, 'confirm' => $archive]),
        'Unknown action',
        'the "' . $retired . '" action is rejected outright'
    );
}
$t->is(count($plugin->repository()->listArchives()['archives']), 1, 'none of those rejected actions touched the repository');

$out = $post(['action' => 'run_check']);
$t->contains($out, 'Check started', 'run_check queues a job');
$checkJob = $t->waitForJob($plugin, $plugin->jobs()->recent(1)[0]->id, 300);
$t->is($checkJob?->status(), Job::STATUS_SUCCESS, 'the repository check passes: ' . ($checkJob?->message() ?? ''));

$t->contains($post(['action' => 'break_lock']), 'Repository lock released', 'break_lock runs');

// ============================================ encrypted repository, end to end

$t->group('Encrypted repository');

$encRepo = '/backup/encrypted-repo';
$plugin->filesystem()->remove($encRepo);

$encPassphrase = 'correct horse battery staple';

// Again the external backup's job, not the plugin's.
$encInit = $externalInit($encRepo, 'repokey-blake2', $encPassphrase);
$t->ok($encInit->isSuccessful(), 'the external backup creates an encrypted repository: ' . ($encInit->isSuccessful() ? 'ok' : $encInit->errorMessage()));
$encCreated = $externalArchive($encRepo, 'enc-1', ['/home/alice'], $encPassphrase);
$t->ok($encCreated->isSuccessful() || $encCreated->isWarning(), 'it writes an encrypted archive: ' . $encCreated->errorMessage());

$plugin->config()->save(['repository' => $encRepo]);
$plugin->config()->setPassphrase($encPassphrase);
$t->ok($plugin->config()->load()->hasPassphrase(), 'the passphrase is stored');

$encInfo = $plugin->repository()->info();
$t->ok($encInfo->isSuccessful(), 'the plugin opens an encrypted repository with the stored passphrase');
$t->is($encInfo->json()['encryption']['mode'] ?? null, 'repokey-blake2', 'the encryption mode is detected, not configured');

$encArchives = $plugin->repository()->listArchives();
$t->ok($encArchives['result']->isSuccessful(), 'the encrypted repository can be listed with the stored passphrase');
$t->is(count($encArchives['archives']), 1, 'the encrypted archive is there');

// The passphrase must travel in the environment, never as an argument, or it
// would be visible in ps output to every user on the server.
$t->notContains($encArchives['result']->commandLine, 'correct horse', 'the passphrase is not on the command line');

$encRestore = $plugin->jobs()->create(Job::TYPE_RESTORE, 'alice', [
    'archive'     => $encArchives['archives'][0]->name,
    'paths'       => ['/home/alice/.my.cnf'],
    'destination' => '/home/alice/borg_restore_enc',
    'chown_to'    => 'alice',
]);
$runJob($encRestore);
$t->is($plugin->jobs()->find($encRestore->id)->status(), Job::STATUS_SUCCESS, 'restoring from an encrypted repository works');
$t->is(
    trim((string) @file_get_contents('/home/alice/borg_restore_enc/home/alice/.my.cnf')),
    'alice db password',
    'the decrypted content is correct'
);

// Without the passphrase the repository is unreadable, which is the point.
$plugin->config()->setPassphrase('');
$t->notOk($plugin->repository()->listArchives()['result']->isSuccessful(), 'the repository is unreadable without the passphrase');

// And the save path says so rather than reporting a missing repository.
$encOut = $post(['action' => 'save_repository', 'repository' => $encRepo]);
$t->notContains($encOut, 'Repository found and saved', 'an unreadable encrypted repository is not saved as if it were fine');
$plugin->config()->setPassphrase($encPassphrase);

// ============================================================ remote over SSH

$t->group('Remote repository over SSH');

$sshUp = @fsockopen('127.0.0.1', 22, $errno, $errstr, 2);
if ($sshUp === false) {
    $t->ok(true, 'SKIPPED: no sshd reachable in this container');
} else {
    fclose($sshUp);

    $plugin->filesystem()->remove('/backup/remote-repo');
    $remoteRepo = 'ssh://root@localhost:22/backup/remote-repo';
    $remoteRsh = 'ssh -i /root/.ssh/borg_ed25519 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null';

    $remoteInit = $externalInit($remoteRepo, 'none', '', $remoteRsh);
    $t->ok($remoteInit->isSuccessful(), 'the external backup creates a remote repository over ssh:// : ' . ($remoteInit->isSuccessful() ? 'ok' : $remoteInit->errorMessage()));
    $remoteCreated = $externalArchive($remoteRepo, 'remote-1', ['/home/alice'], '', $remoteRsh);
    $t->ok($remoteCreated->isSuccessful() || $remoteCreated->isWarning(), 'it writes a remote archive: ' . $remoteCreated->errorMessage());

    $plugin->config()->setPassphrase('');

    // BORG_RSH has to be right before the probe can succeed, which is what
    // makes verify-on-save useful for a remote repository: a missing key is
    // caught here rather than at the moment someone needs a restore.
    $t->contains(
        $post(['action' => 'save_repository', 'repository' => $remoteRepo, 'ssh_command' => '']),
        'Could not read the repository',
        'a remote repository without the right BORG_RSH is refused'
    );

    $t->contains(
        $post(['action' => 'save_repository', 'repository' => $remoteRepo, 'ssh_command' => $remoteRsh]),
        'Repository found and saved',
        'it saves once BORG_RSH is correct'
    );

    $remoteArchives = $plugin->repository()->listArchives();
    $t->ok($remoteArchives['result']->isSuccessful(), 'the remote repository can be listed');
    $t->is(count($remoteArchives['archives']), 1, 'the remote archive is there');
}

// Back to the local repository for anything that follows.
$plugin->config()->save(['repository' => '/backup/test-repo', 'ssh_command' => '']);
$plugin->config()->setPassphrase('');

// ====================================================== older borg (1.1) args

$t->group('borg 1.1 compatibility');

// A stub that reports 1.1, so the --prefix branch is covered without a second
// borg installation. Only argument shape is asserted; nothing is executed.
$stub = '/usr/local/bin/borg-1.1-stub';
if (is_executable($stub)) {
    $oldRunner = new BorgRunner($stub, '/root');
    $t->is($oldRunner->version(), '1.1.18', 'the stub reports borg 1.1');
    $t->notOk($oldRunner->isUnsupportedMajor(), 'borg 1.1 is still a supported major version');

    // The commands a restore needs are spelled the same on 1.1 as on 1.2, which
    // is why dropping the backup side also dropped the version-dependent flags.
    $oldRepo = new Repository($oldRunner, $plugin->config()->load());
    $t->is($oldRepo->checkArguments()[0], 'check', 'the check command is version-independent');
    $t->is($oldRepo->extractArguments($archive, ['/home/alice'])[0], 'extract', 'the extract command is version-independent');
} else {
    $t->ok(true, 'SKIPPED: no borg 1.1 stub in this container');
}

// ================================================== truncated directory listing

$t->group('Oversized directory listings');

// The cap exists so a home directory with tens of thousands of files cannot
// stall the page; forcing a small limit exercises it without building one.
$capped = $plugin->repository()->listDirectory($archive, '/home/alice', 2);
$t->ok($capped->truncated, 'a directory larger than the cap is reported as truncated');
$t->ok(count($capped->entries) <= 3, 'the listing stops at the cap');

$uncapped = $plugin->repository()->listDirectory($archive, '/home/alice');
$t->notOk($uncapped->truncated, 'the same directory is not truncated at the normal cap');

$t->contains(
    $t->page('admin', 'admin', ['tab' => 'archives', 'archive' => $archive, 'path' => '/home/alice']),
    'alice',
    'the browse page still renders for that directory'
);

// ================================================================ job cleanup

$t->group('Job retention');

$oldJob = $plugin->jobs()->create(Job::TYPE_CHECK, 'retention-test', []);
$jobFile = $plugin->paths->jobFile($oldJob->id);
$logFile = $plugin->paths->logFile($oldJob->id);
$t->ok(is_file($jobFile) && is_file($logFile), 'a job writes a record and a log');

// Backdate it past the retention window.
@touch($jobFile, time() - (40 * 86400));
@touch($logFile, time() - (40 * 86400));

$removed = $plugin->jobs()->purgeOlderThan(30);
$t->ok($removed >= 1, 'purging removes records older than the window');
$t->notOk(is_file($jobFile), 'the old job record is gone');
$t->notOk(is_file($logFile), 'its log is gone too');
$t->ok($plugin->jobs()->find($oldJob->id) === null, 'the purged job is no longer findable');

$recentJob = $plugin->jobs()->create(Job::TYPE_CHECK, 'retention-test', []);
$plugin->jobs()->purgeOlderThan(30);
$t->ok($plugin->jobs()->find($recentJob->id) !== null, 'a recent job survives purging');

// ================================================================= long logs

$t->group('Large job logs');

$logJob = $plugin->jobs()->create(Job::TYPE_CHECK, 'log-test', []);
for ($i = 1; $i <= 4000; ++$i) {
    $plugin->jobs()->appendLog($logJob, 'line ' . $i . str_repeat(' padding', 4) . "\n");
}
$tail = $plugin->jobs()->tail($logJob, 50);
$lines = explode("\n", trim($tail));

$t->is(count($lines), 50, 'the tail returns the requested number of lines');
$t->contains($tail, 'line 4000', 'the tail ends at the newest line');
$t->notContains($tail, 'line 3000', 'older lines are not included');
// Seeking into the middle of a large file lands mid-line; that partial must go.
$t->ok(str_starts_with(trim($lines[0]), 'line '), 'the first line returned is whole, not a fragment');

$t->group('The lock is free as soon as a job reports finished');

// The UI polls the job record; if the lock outlived the status write, an
// operator clicking "Check repository" the moment the last check finished would
// be told another operation was running.
$lockRace = $plugin->jobs()->create(Job::TYPE_CHECK, 'lock-race', []);
$plugin->dispatcher()->dispatch($lockRace);
$t->ok($t->waitForJob($plugin, $lockRace->id, 180)?->isFinished() === true, 'a dispatched check finishes');

$immediate = $plugin->lockFactory()->createLock('borg-repository', 30.0, false);
$t->ok($immediate->acquire(), 'the repository lock is free the instant the job reads as finished');
$immediate->release();

// =============================================================== uninstalling

$t->group('Uninstaller');

// A server upgraded from 1.x can still have the old schedule on it, pointing at
// a command this version no longer has. The uninstaller has to clear it.
$legacyCron = '/tmp/borg-plugin-test-legacy-cron';
file_put_contents($legacyCron, "# left behind by 1.x\n30 3 * * * root /bin/true\n");
$t->ok(is_file($legacyCron), 'a legacy schedule exists before uninstalling');

$uninstall = $t->exec(
    ['/bin/sh', $pluginDir . '/scripts/uninstall.sh'],
    $t->environment() + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'BORG_PLUGIN_CRON_FILE' => $legacyCron]
);
$t->is($uninstall['exit'], 0, 'uninstall.sh exits cleanly');
$t->notOk(is_file($legacyCron), 'it removes a schedule left by an older version');

// Deleting a customer's only backup because a plugin was removed is not a
// decision an uninstaller gets to make.
$t->ok(is_dir($plugin->paths->dataDir), 'it leaves the plugin state in place');
$t->ok(is_file($plugin->paths->configFile()), 'it leaves the configuration in place');
$t->ok(is_dir('/backup/test-repo'), 'it leaves the borg repository alone');
$t->contains($uninstall['stdout'], 'Left in place on purpose', 'it says what it kept and why');

// =================================================================== verdict

$t->group('Answering: can borg run as the admin user?');
(new PrivilegeProbe($t, $plugin->paths))->run();

exit($t->summary());
