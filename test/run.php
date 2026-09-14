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

$pluginDir = \dirname(__DIR__);
$t = new Harness($pluginDir);
$plugin = $t->reset();

$runJob = static function (Job $job) use ($plugin): int {
    return (new JobRunner($plugin, $plugin->jobs()))->run($job);
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
$t->notEmpty($config->save(['compression' => 'zstd; rm -rf /']), 'rejects shell syntax in compression');
$t->isEmpty($config->save(['compression' => 'zstd,6']), 'accepts a compression level');
$t->notEmpty($config->save(['encryption' => 'rot13']), 'rejects an unknown encryption mode');
$t->notEmpty($config->save(['archive_name' => 'a::b']), 'rejects "::" in the archive template');

$t->notEmpty($config->save(['schedule_hour' => '99']), 'rejects an out-of-range cron hour');
$t->notEmpty($config->save(['schedule_minute' => 'x']), 'rejects a non-numeric cron minute');
$t->notEmpty($config->save(['schedule_hour' => '5-2']), 'rejects an inverted cron range');
$t->notEmpty($config->save(['schedule_minute' => '0,']), 'rejects a trailing comma in a cron list');
$t->isEmpty($config->save(['schedule_hour' => '*/6']), 'accepts a cron step');
$t->isEmpty($config->save(['schedule_minute' => '0,30']), 'accepts a cron list');
$t->isEmpty($config->save(['schedule_hour' => '8-17']), 'accepts a cron range');

$t->notEmpty($config->save(['user_restore_dir' => '../../etc']), 'rejects traversal in the restore directory');
$t->notEmpty($config->save(['user_restore_dir' => 'a/b']), 'rejects a nested restore directory');
$t->notEmpty($config->save(['user_restore_dir' => '..']), 'rejects ".." as the restore directory');
$t->isEmpty($config->save(['user_restore_dir' => 'borg_restore']), 'accepts a plain restore directory name');

$t->notEmpty($config->save(['source_paths' => 'relative/path']), 'rejects a relative source path');
$t->notEmpty($config->save(['source_paths' => '']), 'rejects an empty source path list');
$t->notEmpty($config->save(['keep_daily' => '-1']), 'rejects negative retention');
$t->notEmpty($config->save(['keep_daily' => 'lots']), 'rejects non-numeric retention');

// A rejected save must leave the stored configuration untouched.
$config->save(['repository' => '/backup/test-repo']);
$config->save(['repository' => 'garbage value']);
$t->is($config->load()->repository(), '/backup/test-repo', 'a rejected save does not modify stored config');

$t->group('Configuration hygiene');

$config->save(['source_paths' => "/home\n/home\n  /etc  \n\n"]);
$t->is($config->load()->sourcePaths(), ['/home', '/etc'], 'source paths are trimmed and de-duplicated');
$t->ok(\count(Configuration::DEFAULTS) > 0, 'defaults are defined');
$t->is($config->load()->get('nonexistent_key'), null, 'unknown keys are not readable');

// ======================================================================= CSRF

$t->group('CSRF tokens');

$csrf = new CsrfTokenizer($plugin->paths, $plugin->filesystem());
$token = $csrf->token(PluginRequest::LEVEL_ADMIN, 'admin');

$t->is(\strlen($token), 64, 'token is a sha256 hex digest');
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
$t->is(\count($posted->bodyList('paths')), 2, 'decodes repeated POST fields into a list');
$t->ok($posted->isPost(), 'detects a POST');
$t->is($posted->bodyList('missing'), [], 'a missing list field is empty, not an error');

// =================================================================== borg CLI

$t->group('borg availability');

$runner = new BorgRunner(BorgRunner::locateBinary(), '/root');
$t->ok($runner->isInstalled(), 'borg is installed: ' . ($runner->version() ?? 'n/a'));
$t->notOk($runner->isUnsupportedMajor(), 'borg major version is supported (1.x)');
$t->ok($runner->supportsGlobArchives(), 'borg supports --glob-archives (1.2+)');

$t->group('Repository lifecycle');

$plugin->filesystem()->mkdir('/backup', 0700);
$config->save([
    'repository'       => '/backup/test-repo',
    'encryption'       => 'none',
    'source_paths'     => "/home\n/etc/passwd",
    'exclude_patterns' => 'sh:/home/*/.cache/**',
    'compression'      => 'lz4',
    'archive_name'     => 'test-{now:%Y-%m-%d_%H:%M:%S.%f}',
    'archive_prefix'   => 'test-',
    'prune_enabled'    => false,
]);
$config->setPassphrase('');

$plugin = $t->reset();
// reset() wiped the data directory, so write the working config again.
$plugin->filesystem()->mkdir('/backup', 0700);
$plugin->config()->save([
    'repository'       => '/backup/test-repo',
    'encryption'       => 'none',
    'source_paths'     => "/home\n/etc/passwd",
    'exclude_patterns' => 'sh:/home/*/.cache/**',
    'compression'      => 'lz4',
    'archive_name'     => 'test-{now:%Y-%m-%d_%H:%M:%S.%f}',
    'archive_prefix'   => 'test-',
    'prune_enabled'    => false,
]);

$init = $plugin->repository()->initialize();
$t->ok($init->isSuccessful(), 'borg init succeeds' . ($init->isSuccessful() ? '' : ': ' . $init->errorMessage()));

$t->group('Backup (running as root)');

$job = $plugin->jobs()->create(Job::TYPE_BACKUP, 'test', ['prune' => false]);
$exit = $runJob($job);
$job = $plugin->jobs()->find($job->id);

$t->is($exit, 0, 'the backup job exits cleanly');
$t->ok(
    \in_array($job->status(), [Job::STATUS_SUCCESS, Job::STATUS_WARNING], true),
    'backup reports success (' . $job->status() . ': ' . $job->message() . ')'
);
$t->ok($job->stats() !== null, 'backup statistics are recorded');
$t->ok(($job->stats()['nfiles'] ?? 0) > 0, 'the archive contains files');

$listing = $plugin->repository()->listArchives();
$t->ok($listing['result']->isSuccessful(), 'archives can be listed');
$t->is(\count($listing['archives']), 1, 'exactly one archive exists');
$archive = $listing['archives'][0]->name;

$t->group('Archive contents');

$subtree = $plugin->repository()->listSubtree($archive, '/home/alice');
$paths = array_map(static fn (array $row) => '/' . ltrim((string) $row['path'], '/'), $subtree['rows']);

$t->ok(\in_array('/home/alice/.my.cnf', $paths, true), 'a root-only file IS in the archive');
$t->ok(\in_array('/home/alice/.secret/notes.txt', $paths, true), 'a mode-0600 file IS in the archive');
$t->ok(\in_array('/home/alice/domains/example.com/public_html/index.html', $paths, true), 'website content IS in the archive');

$directory = $plugin->repository()->listDirectory($archive, '/home/alice');
$names = array_map(static fn ($entry) => $entry->name, $directory->entries);

$t->ok(\in_array('domains', $names, true), 'directory listing includes subdirectories');
$t->notOk(\in_array('index.html', $names, true), 'directory listing is one level deep only');
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

$t->contains($t->page('admin', 'admin', ['tab' => 'backup']), 'Retention', 'the backup settings tab renders');
$t->contains($t->page('admin', 'admin', ['tab' => 'jobs']), 'Recent jobs', 'the jobs tab renders');

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

// ================================================================= scheduling

$t->group('Cron file management');

$plugin->config()->save(['schedule_enabled' => true, 'schedule_hour' => '3', 'schedule_minute' => '30']);
$plugin->cron()->apply($plugin->config()->load());

$t->ok($plugin->cron()->isInstalled(), 'the cron file is written when scheduling is enabled');
$cron = (string) @file_get_contents($plugin->cron()->file());
$t->contains($cron, '30 3 * * * root', 'the cron line carries the configured time and runs as root');
$t->contains($cron, 'borg:scheduled-backup', 'the cron line invokes the scheduled backup command');
$t->contains($cron, 'BORG_PLUGIN_DATA_DIR=', 'the cron file forwards the plugin state location');
$t->is(substr(sprintf('%o', fileperms($plugin->cron()->file())), -4), '0644', 'the cron file is 0644 so cron will read it');

$plugin->config()->save(['schedule_enabled' => false]);
$plugin->cron()->apply($plugin->config()->load());
$t->notOk($plugin->cron()->isInstalled(), 'the cron file is removed when scheduling is disabled');

// ====================================================================== prune

$t->group('Prune');

$plugin->config()->save(['prune_enabled' => true, 'keep_daily' => 1, 'keep_weekly' => 0, 'keep_monthly' => 0]);
$pruneArguments = $plugin->repository()->pruneArguments();

$t->ok($pruneArguments !== null, 'prune arguments are produced');
$t->ok(\in_array('--keep-daily=1', $pruneArguments, true), 'retention is passed to borg');
$t->notOk(\in_array('--keep-weekly=0', $pruneArguments, true), 'rules set to 0 are omitted');
$t->ok(\in_array('--glob-archives', $pruneArguments, true), 'pruning is scoped to this plugin\'s archive prefix');
$t->ok(\in_array('test-*', $pruneArguments, true), 'the prefix glob matches the configured prefix');

$pruneJob = $plugin->jobs()->create(Job::TYPE_PRUNE, 'test', []);
$exit = $runJob($pruneJob);
$pruneJob = $plugin->jobs()->find($pruneJob->id);
$t->is($exit, 0, 'the prune job exits cleanly');
$t->is($pruneJob->status(), Job::STATUS_SUCCESS, 'prune succeeds: ' . $pruneJob->message());

// ====================================================== DirectAdmin backups

$t->group('Admin backups configuration');

$t->is($plugin->config()->load()->adminBackupsDir(), '/home/admin/admin_backups', 'defaults to DirectAdmin\'s own location');
$t->notEmpty($plugin->config()->save(['admin_backups_dir' => 'relative/path']), 'rejects a relative admin backups path');
$t->notEmpty($plugin->config()->save(['admin_backups_dir' => '']), 'rejects an empty admin backups path');
$t->notEmpty($plugin->config()->save(['admin_backups_dir' => "/home/admin\nrm -rf /"]), 'rejects a newline in the path');
$t->isEmpty($plugin->config()->save(['admin_backups_dir' => '/home/admin/admin_backups/']), 'accepts an absolute path');
$t->is($plugin->config()->load()->adminBackupsDir(), '/home/admin/admin_backups', 'a trailing slash is normalised away');

$t->ok($plugin->config()->load()->covers('/home/admin/admin_backups'), 'coverage check sees the path inside /home');
$plugin->config()->save(['source_paths' => '/etc']);
$t->notOk($plugin->config()->load()->covers('/home/admin/admin_backups'), 'coverage check spots an uncovered path');
$t->notOk($plugin->config()->load()->covers('/home2'), 'coverage is not fooled by a shared prefix');
$plugin->config()->save(['source_paths' => "/home\n/etc/passwd"]);

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

$t->group('Archive name collisions');

// A realistic misconfiguration: a daily template plus a second run the same
// day. borg's own error names no cause, so the plugin adds one.
$plugin->config()->save(['archive_name' => 'collide-fixed-name']);

$first = $plugin->jobs()->create(Job::TYPE_BACKUP, 'test', ['prune' => false]);
$runJob($first);
$t->is($plugin->jobs()->find($first->id)->status(), Job::STATUS_SUCCESS, 'the first backup with a fixed name succeeds');

$second = $plugin->jobs()->create(Job::TYPE_BACKUP, 'test', ['prune' => false]);
$runJob($second);
$second = $plugin->jobs()->find($second->id);

$t->is($second->status(), Job::STATUS_FAILED, 'a colliding archive name fails rather than silently doing nothing');
$t->contains($second->message(), 'already exists', 'the failure quotes borg\'s reason');
$t->contains($second->message(), 'does not produce a unique name', 'the failure explains the actual cause');
$t->contains($second->message(), 'collide-fixed-name', 'the failure names the offending template');

$plugin->config()->save(['archive_name' => 'test-{now:%Y-%m-%d_%H:%M:%S.%f}']);

// ======================================================================= lock

$t->group('Repository locking');

$lock = $plugin->lockFactory()->createLock('borg-repository', 60.0, false);
$t->ok($lock->acquire(), 'the repository lock can be acquired');

$blocked = $plugin->jobs()->create(Job::TYPE_BACKUP, 'test', ['prune' => false]);
$runJob($blocked);
$blocked = $plugin->jobs()->find($blocked->id);
$t->is($blocked->status(), Job::STATUS_FAILED, 'a second writer is refused while the lock is held');
$t->contains($blocked->message(), 'already running', 'the refusal explains why');

$lock->release();

$afterRelease = $plugin->jobs()->create(Job::TYPE_BACKUP, 'test', ['prune' => false]);
$runJob($afterRelease);
$afterRelease = $plugin->jobs()->find($afterRelease->id);
$t->ok($afterRelease->isFinished() && $afterRelease->status() !== Job::STATUS_FAILED, 'a backup runs once the lock is released');

// A restore only reads, so it must not be blocked by the writer lock.
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

$queued = $plugin->jobs()->create(Job::TYPE_BACKUP, 'test', ['prune' => false]);
$plugin->jobs()->update($queued, ['status' => Job::STATUS_SUCCESS]);
$t->is($t->console(['borg:job', $queued->id])['exit'], 2, 'borg:job refuses to re-run a finished job');

$t->group('Job ordering');

$ordering = [];
for ($i = 0; $i < 5; $i++) {
    $ordering[] = $plugin->jobs()->create(Job::TYPE_CHECK, 'ordering-test', [])->id;
}
$t->ok(\count(array_unique($ordering)) === 5, 'ids created in a tight loop are unique');

$sorted = $ordering;
rsort($sorted, SORT_STRING);
$t->is($sorted, array_reverse($ordering), 'ids sort by creation order even within the same second');

$listed = array_map(
    static fn (Job $job) => $job->id,
    $plugin->jobs()->recent(5, 'ordering-test')
);
$t->is($listed, array_reverse($ordering), 'the job list returns newest first, in true creation order');

$t->group('Detached dispatch');

// The real path a UI-triggered backup takes: a process that must outlive the
// request that started it.
$detached = $plugin->jobs()->create(Job::TYPE_BACKUP, 'test', ['prune' => false]);
$plugin->dispatcher()->dispatch($detached);
$detached = $t->waitForJob($plugin, $detached->id, 180);

$t->ok($detached !== null && $detached->isFinished(), 'a detached job runs to completion after its parent exits');
$t->ok(
    $detached !== null && \in_array($detached->status(), [Job::STATUS_SUCCESS, Job::STATUS_WARNING], true),
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

// =================================================================== verdict

$t->group('Answering: can borg run as the admin user?');
(new PrivilegeProbe($t, $plugin->paths))->run();

exit($t->summary());
