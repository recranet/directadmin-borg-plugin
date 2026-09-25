# DirectAdmin Borg Backup plugin

Browse and restore from the [BorgBackup](https://www.borgbackup.org/) repository
your server already backs up to, from inside DirectAdmin.

**This plugin does not take backups.** It creates no repository, writes no
archive, prunes nothing and installs no schedule. Whatever already fills your
repository — a cron script, a DirectAdmin hook, borgmatic — keeps doing exactly
that, untouched. The plugin is the restore end of it.

- **Admin level** — point the plugin at the existing repository, browse its
  archives, and restore anything to anywhere, including a whole user account.
- **User level** — each customer can browse their own home directory as it was
  at any backup point, put their website or their mail back, and have the
  DirectAdmin backup holding their databases handed to them, without a support
  ticket.

Backup scripts are already solved, usually by something older and more carefully
tuned than a control-panel plugin. Restores are the part that happens under
pressure, at two in the morning, by whoever is on call. That is the part worth
putting a UI on.

Built against the [DirectAdmin Borg
documentation](https://docs.directadmin.com/directadmin/backup-restore-migration/borg.html)
and the [plugin API](https://docs.directadmin.com/developer/plugins/structure.html).

---

## Why the plugin runs as root

`plugin.conf` sets `admin_run_as=root` and `user_run_as=root`. That is a
requirement, not a convenience: the obvious alternative — run borg as `admin`,
perhaps with a shared group — does not work. The test suite settles it by taking
the same backup twice against identical data (`test/lib/PrivilegeProbe.php`):

| | as `admin` | as `root` |
|---|---|---|
| Execute `/usr/bin/borg` | yes | yes |
| Create and own a repository | yes | yes |
| Read `/home/<user>/…` (mode `0711`) | **no** | yes |
| Entries captured from a two-customer `/home` | **5** | **32** |
| Exit code | **1 (warning)** | 0 |

A shared group changes nothing, because executing borg was never the problem —
`/usr/bin/borg` is already `0755`. Reading the data is. DirectAdmin gives each
customer home directory mode `0711`, so `admin` cannot traverse into it, and
`/etc/shadow`, mail spools and `/usr/local/directadmin/conf` are equally out of
reach. The dangerous part is the exit code: borg treats unreadable files as a
**warning**, so a backup run as `admin` exits 1, looks like it worked, and
silently contains almost nothing.

Running plugin code as root has been supported natively since DirectAdmin
1.689. Because the user-level page runs as root too, every path arriving
from a request is normalised and confined to the requesting account's home
twice — once in the page, and again in the worker at the point of use
(`src/Security/PathGuard.php`, `src/Security/Account.php`).

Root reads the repository; it does not write into a customer's home. Every
restore, delete and delivery there is done as the account itself
(`src/Security/AccountFilesystem.php`): borg streams the files out as root and
a process running as the customer writes them. A customer owns every directory
in their home and can replace any of them with a symlink at any moment, so a
check on the path before root writes cannot hold; running as the customer means
the kernel applies their own permissions at every step instead.

---

## Requirements

| | |
|---|---|
| DirectAdmin | 1.689 or newer (for `*_run_as=root`) |
| PHP | 8.2 or newer, as the CLI at `/usr/local/bin/php` |
| borg | 1.1–1.4 (borg 2.x changed the CLI and is not supported yet) |

PHP is the native CustomBuild CLI (`php1_release`), not alt-php: DirectAdmin
executes plugin scripts through their shebang, which `install.sh` rewrites to
the binary it finds. Scripts run with `-n` (no `php.ini`) so a hardened CLI ini
cannot disable `proc_open` underneath the plugin.

Install borg first:

```sh
dnf install -y epel-release && dnf install -y borgbackup   # RHEL / Alma / Rocky
apt-get install -y borgbackup                              # Debian / Ubuntu
```

---

## Installing

From the release tarball (ships `vendor/`, so the server needs neither
composer nor network access): Admin Level → Plugin Manager → Add Plugin → upload
`borg.tar.gz`.

Upload it under exactly that name. DirectAdmin names the plugin directory after
the tarball minus `.tar.gz`, and that name is also the URL prefix the menu
entries use (`/CMD_PLUGINS_ADMIN/borg/`), so anything else installs a plugin
whose every link and icon 404s. The version is in `plugin.conf`, not the
filename.

Or from a shell:

```sh
mkdir -p /usr/local/directadmin/plugins/borg
tar -xzf borg.tar.gz -C /usr/local/directadmin/plugins/borg
sh /usr/local/directadmin/plugins/borg/scripts/install.sh
```

`install.sh` finds a suitable PHP, rewrites the shebangs, verifies the version
and required extensions, sets ownership and permissions, and creates the state
directory. From a source checkout, build the tarball first with `make package`.

### Installing or updating several servers

`scripts/deploy.sh` does the whole thing over ssh, for one host or a fleet:

```sh
make deploy HOSTS="host1 host2"    # builds the tarball, then deploys
sh scripts/deploy.sh host1 host2   # deploys dist/borg.tar.gz as it stands
```

Hosts are arguments rather than a list in the repository, because which servers
run this plugin is deployment detail.

Each host is refused *before* anything is touched if its `/usr/local/bin/php` is
below the floor, borg is missing, or a job is running. The PHP check is the one
that earns its place: `install.sh` only runs after the swap, so a server that
cannot meet the floor would otherwise be left holding a plugin it cannot start.

Then it extracts to `plugins/borg.new`, checks that tree is complete, renames
the running plugin to `/root/borg.bak-<version>` and the new one into place, and
runs `install.sh`. A swap rather than an extract over the top, so a file deleted
since the installed version does not survive the upgrade. The rollback copy goes
to `/root` and never beside the plugin — DirectAdmin treats any directory under
`plugins/` holding a `plugin.conf` as an installed plugin, so a copy there shows
up as a second "Borg Backup" in the Plugin Manager.

Afterwards it verifies the version, that `config.json` is byte-identical, that
no `borg.new` or rollback copy was left in `plugins/`, that `borg:status` reads
the repository, and that the admin and user pages render. That last one is not
redundant: `borg:status` goes through the console and would miss a template or
request-decoding failure, which is exactly what a dependency bump can introduce.

To roll back, move the copy back — no `install.sh`, and nothing in
`/var/lib/directadmin-borg/` was touched:

```sh
ssh root@host 'cd /usr/local/directadmin/plugins &&
               rm -rf borg && mv /root/borg.bak-2.3.0 borg'
```

### Upgrading from 1.x

2.0 removed the backup side entirely. Upload the new tarball as usual; nothing
in `/var/lib/directadmin-borg/` is touched, so the repository location and
passphrase carry over. `install.sh` removes two things a 1.x install could have
left behind, because both would keep firing at commands that no longer exist:
`/etc/cron.d/directadmin-borg` and
`/usr/local/directadmin/scripts/custom/all_backups_post/borg-plugin.sh`. The
retired settings (source paths, compression, retention, schedule) are dropped
from `config.json` the first time it is saved.

**Your own backup script and its cron entry are not touched** — the plugin never
installed them. If you were relying on the plugin to take backups, set up a
`borg create` cron job before upgrading.

---

## Using it

### First run

**Repository** — enter the location your backups already go to, and save.

- local: `/mnt/bigstorage/borg/$(hostname)`
- remote: `ssh://borgbackup@10.0.0.5:22/backups/$(hostname -f)`
- remote (scp-style): `borgbackup@10.0.0.5:/backups/host`

The exact path matters: borg repositories are normally per-host, so
`/mnt/bigstorage/borg` is usually the *parent* of the one you want. Read it out
of the script that runs your backups rather than typing it from memory.

The location is verified before it is stored — the plugin runs `borg info` and
saves only if borg finds a repository there. There is no "initialise" button,
deliberately: a mistyped path that silently created a second, empty repository
would look like a working configuration right up until someone needed a restore.

For an encrypted repository, store the passphrase in the same form. For a remote
one, set **SSH command** to whatever your backup script exports as `BORG_RSH`,
e.g. `ssh -i /root/.ssh/borg_hetzner -p 23`. Both are needed for the
verification to pass, so a missing key is caught now rather than during a
recovery.

Settings live in `/var/lib/directadmin-borg/config.json` (mode 0600), outside
the plugin directory, so a plugin update does not lose them.

### What gets backed up, and by what

Not by this plugin. Whatever writes to the repository decides what is in it —
typically a script like:

```sh
REPOSITORY=/mnt/bigstorage/borg/`hostname`

borg create -v --stats $REPOSITORY::'{fqdn}-{now:%Y-%m-%d_%H:%M}' \
    /home /etc /usr/local/directadmin /var/log --exclude /home/mysql

borg prune -v $REPOSITORY --prefix '{fqdn}-' --keep-daily=14 --keep-weekly=8
```

Two things there decide what you can restore:

- **`/home` must be included**, or there is nothing to give a customer back.
- **DirectAdmin's own per-user backups must be included.** Databases are not in
  a home directory; DirectAdmin dumps them into its backups directory —
  `/home/admin/admin_backups/` by default, but often somewhere else entirely,
  such as `/mnt/bigstorage/directadmin/`. Wherever it is, it has to be in the
  archive, and the plugin has to be told where under **Repository → DirectAdmin
  backups directory**. The files are named `<level>.<creator>.<user>.tar.zst`
  (`user.admin.alice.tar.zst`); the plain `<user>.tar.zst` form is accepted too.
  Restoring a whole account needs both, from the same archive.

### What a borg "version" is

There are no version numbers. A repository holds archives, and each archive
is a complete, named snapshot of everything the source paths contained when it
ran. Deduplication means a snapshot only stores the chunks that changed, so a
hundred daily archives cost far less than a hundred copies — but each one still
restores independently and in full. There is no chain of increments to replay.

Two separate things carry the date. The **archive name** comes from the name
template (`{hostname}-{now:%Y-%m-%d_%H:%M:%S}` → `srv01-2026-09-14_03:30:00`)
and is only a label; change the template and old archives keep their old names.
The **creation timestamp** is recorded by borg itself and cannot be spoofed by
renaming. The plugin sorts and displays by the timestamp, so a changed template
never disturbs the ordering — which is why the user-level page can show dates
alone where the admin pages show both.

"Restore this borg version" therefore means "restore from this archive", and
because archives are independent snapshots you can restore a user's home
directory and their DirectAdmin backup from the *same* archive and know the two
are consistent.

### Retention

Also not this plugin's. Retention is whatever your `borg prune` invocation says,
and the plugin will not prune, compact or delete an archive — no UI for it and
no code path to it. It cannot know which archives in a shared repository belong
to which producer, and deleting the wrong one is not recoverable.

### Browsing an archive

borg 1.x cannot list one directory. `borg list` walks the whole item metadata
stream however little you ask it for, so every directory click costs a full scan
— seventeen seconds on a 2.5-million-entry archive — and asking for the root
without a path makes borg print all 2.5 million entries, ~880 MB, enough to kill
the page.

So an archive is scanned **once**, in the background, and the result written to
disk. Open an unindexed archive and the plugin offers to do it, with live
progress under Jobs; afterwards every directory in that archive opens in about
150 ms. Archives are immutable, so an index never needs rebuilding, and one is
discarded when its archive is pruned away.

By default the index records the directory tree only. On a real hosting
server files outnumber directories five to one — 2,116,438 against 408,851 — so
skipping them makes the index a fraction of the size and builds it sooner, and
it costs nothing in what can be recovered: restoring a directory extracts
everything inside it whether or not the index listed the files. What it costs is
ticking one file out of a directory, which is what **Index individual files as
well as directories** turns back on, per archive or as the default. Symlinks are
always indexed — there are few of them, and one is usually `public_html`
pointing somewhere unexpected.

The index lives in `/var/lib/directadmin-borg/index/` as a bytewise-sorted,
tab-separated file per archive, looked up by binary search over byte offsets.
Not SQLite: plugin scripts run on `php -n`, where no extension is guaranteed to
be loaded. The only outside tool is `sort(1)`.

User Level reads the same index and offers the same one-off scan — a **Prepare
this backup** button, a live log, and a page that reloads itself when the scan
finishes, so a customer never waits on an administrator. It used to fall back to
asking borg for their own subtree instead, a full pass over the archive for one
directory listing: the page simply hung. Customer-triggered scans run one at a
time across the server, because sixty customers each opening their own backup
would otherwise be sixty passes over the same repository.

### Restores

Opening an archive lists the **accounts** in it, not its filesystem root. The
root of a DirectAdmin backup is `home` and `etc`, and getting from there to a
customer is four clicks through directories with one interesting child each.
Accounts DirectAdmin no longer has are marked, because that is a different
recovery — DirectAdmin must recreate the account from its own backup before a
home directory means anything.

Picking an account offers the three things that actually get asked for:

- **Restore Domains** — websites, back into `/home/<user>/domains`
- **Restore Email** — mailboxes, back into `/home/<user>/imap`
- **Restore Databases** — the DirectAdmin backup, into `/home/<user>/backups`

The first two restore **in place**, to the path the files came from, with their
original ownership. There is no destination to choose: restoring
`/home/alice/domains` anywhere else produces a copy that then has to be moved by
hand with the right ownership, which is not finishing the job. Each subtree is
checked against the account's home before the job is queued, so "in place"
cannot be talked into meaning somewhere else.

**Restore the whole account** adds the DirectAdmin backup holding the databases
and account configuration. **Browse files** is still there underneath, scoped to
that account, for the one file a customer deleted.

**Users**, at User Level, get the same screen with the account picker removed —
the account is not a choice there, it is whoever is logged in. A customer should
not have to know that "my site is broken" means `domains` and "my mail is gone"
means `imap`; that translation is exactly what the admin screen does, and there
is no reason the person who actually has the problem gets less of it. Both
levels run the same code (`src/Job/AccountTreeRestore.php`), because the
boundary a customer restore depends on is the boundary an admin restore uses,
and two copies of it would be two things to keep right.

Both levels ask for a tick before a restore goes back over live files. That
started as the customer's guard, but the reasoning was never about who is logged
in: the admin screen's typed username only ever guarded the *deletion*, so the
ordinary in-place restore — the one that actually gets clicked — went through on
a single click there. It now asks on both.

Restoring `imap` writes maildirs back underneath a running dovecot, at either
level. It is an overlay, so mail that arrived since stays and deleted messages
come back; what the page warns about is that a mail client may re-download
afterwards. Whether dovecot's indexes want rebuilding is not something this
plugin decides — it does not touch them.

Restored files belong to the account, because the account is what writes them.
That also means they come back with its own group: a file in the home that was
owned by a group the account is not in — `mail` under `imap/`, say — comes back
in the account's group instead. Mail delivery and dovecot go by the owner, so
this does not stop mail working. ACLs and extended attributes are not restored.

Turn the whole user-level feature off with **Repository → Let users restore
their own files**.

### Restore and pre-clean are different operations

Worth being explicit about, because "restore the backup" sounds like it should
already mean this. A restore **overwrites**: every file in the backup replaces
the one that is there, and every file *not* in the backup is left exactly where
it is. That is what someone wants when a file was edited by mistake — and
precisely not what they want when the site was hacked, because the webshell
dropped in last week is not in the backup, so nothing replaces it and it
survives untouched.

**Delete `/home/<user>/domains` first** is the other operation: empty the
directory, then extract, so what is left is exactly what the backup held.
Anything added since is gone with it — new sites, uploads, this month's customer
data. It is never implied, at either level: tick it, and type the account name.

Both levels offer it, for the same reason the restore itself is offered at both:
the customer whose site was defaced is the one who notices, and telling them
their only option is an overlay that leaves the attacker's file in place is
telling them to open a ticket. The deletion is bounded by the account's own home
in the page, again in `AccountTreeRestore::queue()`, and again in the worker
before anything is removed; the home directory itself can never be the thing
emptied, and a symlink out of the tree is removed rather than followed. The
deletion runs as the account, so it can remove exactly what the customer could
remove themselves — a tree holding something they cannot delete fails the
restore rather than being cleared as root.

Delete the whole `domains/example.com`, not just `public_html`. Where
`public_html` is a symlink into a repository checkout beside it, removing the
link deletes the link and leaves the real files — malware included.

Files are only half of it. Databases and DirectAdmin configuration live in the
admin backup, so if the account was already compromised when that archive was
taken, restoring it restores the compromise. Pick an archive from before the
break-in, and rotate database, FTP and SSH credentials afterwards.

### Restoring databases

Databases are the one thing a home directory does not contain, and this is the
only restore in the plugin that does not finish the job itself.

They live in DirectAdmin's own per-user backup —
`/home/admin/admin_backups/user.admin.<user>.tar.zst` — and the thing that knows
how to import them is DirectAdmin's restore screen. So **Restore Databases**
extracts that tarball out of the archive and puts it in `/home/<user>/backups`,
owned by the account. Then: log in as that user, **User Level → Create/Restore
Backups → Restore Backups**, pick the file, tick **Databases**, restore.

**Customers get this button too**, for their own account — if anything it is
more at home there, since the screen that imports the dump is a User Level
screen, so the person who has to drive it is already logged in where the file
lands. This is a route DirectAdmin documents: an admin-level backup placed in a
user's `backups` directory and chowned to them restores from User Level. The
plugin loads no SQL of its own and never will — a control-panel plugin
reimplementing `mysql <` against a live account is a worse version of something
DirectAdmin already does properly.

Only ever the account's own tarball goes there. DirectAdmin's user-level restore
offers whatever is in that directory to the restoring user, so putting one
customer's backup in another's directory would hand over their databases; the
match is the same dot-anchored one used everywhere else in the plugin
(`.<user>.tar.<ext>`, so `user.admin.beaujean.tar.zst` is not `jean`'s).

Unlike **Restore Domains**, there is no tick-and-type-the-username: this copies
one file and destroys nothing. The step that replaces live data is the one you
take on DirectAdmin's screen afterwards, with its own confirmation.

Two things it does not do, both stated on the page:

- **It does not clean up.** The tarball stays in `/home/<user>/backups` and
  counts against the customer's quota — these run from 18 MB to several hundred.
  Delete it once the restore is done.
- **It does not create the account.** For a user DirectAdmin no longer has,
  follow the sequence below instead.

Under the hood borg streams the one file out of the archive, and the account
writes it under a hidden name in `/home/<user>/backups`, sets it to `0600` and
renames it into place. The rename is about the file appearing at its final name
complete or not at all. DirectAdmin offers whatever is in that directory as
something to restore from, and a write is not atomic: written at its final name,
the tarball would sit there growing for the length of the run, and a job that
died halfway would leave a truncated one under exactly the name a good one has.
A symlink where `/home/<user>/backups` should be is refused up front with a
message saying so, but that check is not what makes this safe — every write,
the mode and the rename are done as the account, so a link planted at any of
those names reaches nothing the customer could not reach themselves.

Turn the whole thing off with **Repository → Use DirectAdmin's own per-user
backups**, which also drops the tarball from a whole-account restore.

### Restoring a whole user

A home directory is only half an account. DirectAdmin keeps the rest — the
account's configuration and its database dumps — in its own per-user backup
under `/home/admin/admin_backups/`. Open an archive and use **Restore a whole
user**: give a username, and the plugin restores their home directory *and* that
tarball together. Because those tarballs live under `/home/admin` they are
admin-only; a customer's self-service restore cannot see or fetch them.

**This plugin never creates accounts.** If you name a user DirectAdmin does not
have, the home-directory restore is refused rather than guessed at — restoring
files into a home for an account that does not exist leaves orphaned data with
no owner. You are prompted through the right order instead:

1. **Archives** → pick the archive from before the deletion.
2. **Restore a whole user** → enter the username. The account is gone, so the
   home-directory restore is refused.
3. Click **Restore `<user>`'s DirectAdmin backup**. With *“Place it in
   /home/admin/admin_backups”* ticked (the default) the tarball goes straight
   back to where DirectAdmin reads it, with its original ownership — which
   DirectAdmin requires of those files.
4. **Admin Level → Restore Backups** → restore that tarball. *This* is what
   recreates the account, its databases and its DirectAdmin configuration.
5. Back in **Archives → Restore a whole user**, enter the username again. Now
   that the account exists, tick **Restore to the original location**.

Step 5 is an overlay, not a mirror, but for a freshly recreated account the home
is effectively empty, so the result is exact.

If the account exists but has no tarball in that archive, the home directory is
still restored and you are told plainly that the databases and configuration are
not included. And if a restore setting changes after an archive was indexed —
the DirectAdmin backups directory, say — the archive list marks it **needs
refresh** and offers a button, because the old index would otherwise report that
an account has no DirectAdmin backup when it has one somewhere the index was
never told to look.

---

## Operating it from the shell

```sh
/usr/local/directadmin/plugins/borg/bin/console borg:status
```

Shows the borg version, the uid the plugin runs as, the configured repository
and what borg reports about it (id, encryption mode), recent archives and recent
jobs — the fastest way to find out whether the plugin can read the repository at
all, and whether last night's backup actually wrote anything.

The only other command is `borg:job <id>`, the detached worker behind restores
and checks. There is no scheduled-backup or hook command: this plugin is never
the thing that runs a backup.

---

## How it is put together

```
plugin.conf              admin_run_as=root, user_run_as=root, menu entries
bootstrap.php            SAPI/PHP-version guard, autoloader, umask
admin/, user/            DirectAdmin entry points (index.html, status.raw, menu.raw)
bin/console              Symfony Console app: detached worker, diagnostics
hooks/                   classic-skin menu fragments
src/
  Borg/                  borg CLI wrapper, read-only repository operations,
                         the archive index and archive-name parsing
  Config/                configuration, validation constraints
  Http/                  DirectAdmin request decoding, JSON status endpoint
  Job/                   job records, dispatch, execution (restore and check),
                         and the restores both access levels share
  Security/              path confinement, account resolution, CSRF
  Ui/                    page controllers
templates/               Twig templates (auto-escaped)
test/                    Docker harness and suite
```

State lives in `/var/lib/directadmin-borg/` — **outside** the plugin directory,
because DirectAdmin replaces the whole plugin tree on update and would otherwise
take the repository location, passphrase and job history with it.

```
/var/lib/directadmin-borg/
  config.json      0600   repository location and restore settings
  passphrase       0600   borg passphrase, kept apart from the config
  csrf.key         0600   HMAC key for form tokens
  jobs/            0700   one JSON record per job
  logs/            0700   one log per job
  locks/           0700   repository lock
  cache/           0700   compiled templates
  index/           0700   one sorted index per archive, built on demand
```

The index is the only thing here that can get large: roughly 67 MB per archive
for the directory tree of a 2.5-million-entry backup, or several times that with
files included. It is disposable — deleting it costs one rescan.

### Which Symfony components, and why

All of them are Symfony 7.4, the current LTS. The branch is decided by the PHP
floor rather than chosen: 7.x requires 8.2, which is why the plugin sat on 6.4
until the floor moved up, and 8.0 requires 8.4, which no fleet runs yet.

| Component | Used for |
|---|---|
| `symfony/process` | Running borg with an argument array — repository names, archive names and paths reach `execve()` directly and can never be shell syntax. Also gives timeouts and live output streaming for job logs. |
| `symfony/filesystem` | `dumpFile()` writes config and job records atomically, so a crash mid-write cannot truncate them. Plus ownership handling for restores. |
| `symfony/validator` | Configuration rules as declarative constraints — the repository location and `BORG_RSH` both end up on a borg command line, so the rules are about safety as much as correctness. |
| `symfony/lock` | The repository lock, so two overlapping repository checks become a clear "already running" instead of a borg lock error. Restores deliberately do not take it. |
| `symfony/finder` | Job listing and recursive ownership fixes. |
| `symfony/http-foundation` | Rebuilding a real `Request` from DirectAdmin's environment variables, for typed input access. |
| `symfony/console` | The detached worker and the diagnostics command. |
| `twig/twig` | Auto-escaped templates — every value on these pages is a path, an archive name or a borg error message, and all three are attacker-influenced. |

### How long-running work is handled

A restore outlives any HTTP request, so the UI records a job and starts
`bin/console borg:job <id>` detached. Only the job id crosses that boundary; the
worker reads its parameters from the job file, so no request data ever reaches a
command line. The launch double-forks through `sh` deliberately — Symfony's
`Process` destructor stops any child it still owns, which would kill the job the
moment the request finished.

The UI then polls `status.raw`, which streams the job log. At user level that
endpoint only returns a job owned by the caller, and reports anything else as
`404` rather than `403`, so it cannot be used to probe for job ids.

---

## Code quality

```sh
make check     # phpstan + cs + lint + test
```

| Target | What it does |
|---|---|
| `make stan` | PHPStan at level 8 |
| `make cs` / `make cs-fix` | Coding standards, report only / apply |
| `make lint` | Parse-check PHP, compile every Twig template |
| `make test` | Full suite against real borg |

PHPStan runs with no baseline and no ignored errors — the findings it raised
were fixed rather than suppressed. Coding standards are PSR-12 plus the Symfony
ruleset, with the risky rules enabled: `strict_comparison` and `strict_param`
catch real bugs, not just layout.

All tooling runs in containers pinned to PHP 8.2, so results do not depend on
the local PHP. Dev dependencies are `require-dev`, and `scripts/package.sh`
builds `vendor/` inside its staging copy with `--no-dev`, so neither PHPStan nor
PHP-CS-Fixer reaches a production server — and packaging never disturbs the
working tree.

## Testing

```sh
make test                          # the environments that mirror production
test/docker-test.sh alma9          # just one
test/docker-test.sh alma9-borg14   # opt-in: borg newer than EPEL ships
```

| Environment | PHP | borg | Why |
|---|---|---|---|
| `alma9` | 8.2 | 1.2.9 (EPEL 9) | The common production shape |
| `alma8` | 8.2 | 1.1.18 (EPEL 8) | Still widely deployed, and the only place borg 1.1 still ships |
| `alma9-borg14` | 8.2 | 1.4.5 (pip) | Not in the default run |

All images are AlmaLinux, because that is what DirectAdmin servers run, and borg
is installed the way a real server installs it, from EPEL. The spread across
those two axes is the point: the commands a restore needs are spelled the same
on borg 1.1 as on 1.4, but that is an assertion, not an assumption, and `alma8`
is what proves it against a real borg 1.1 rather than a stub. `alma9-borg14` is
out of the default run because no EL repository carries borg 1.4, so installing
it means pip and a build toolchain — it is there for the day EPEL moves.

PHP is 8.2 on both, which is both the floor and the oldest version any server
runs — so unlike before, the matrix exercises the minimum rather than sitting
above it. `make lint` and `make stan` run on 8.2 too, so syntax newer than the
floor cannot slip in.

Each image carries a `/home` with two customer accounts at DirectAdmin's `0711`
permissions, DirectAdmin-style admin backups, an sshd for remote-repository
tests, and a stub borg for version-branch tests. Each run installs the plugin
with the production `install.sh`, then drives the real entry points the way
DirectAdmin does — environment in, stdout out. No DirectAdmin server is
involved, and nothing outside the container is touched.

The repository is created and filled by the suite itself, shelling out to borg
directly, standing in for the server's own backup script. The plugin is only
ever pointed at the result — so if a change ever made the plugin start writing
archives, the archive counts would stop matching.

What it covers, beyond the happy paths: path confinement and traversal,
cross-account access through both the page and the worker, CSRF, the DirectAdmin
env-decoding path, template escaping, repository locking, detached dispatch,
secret handling and file permissions; encrypted and remote (`ssh://`)
repositories; every admin form action and every tab rendering under `php -n`;
the retired backup actions being rejected outright; the archive index against
what borg actually returns; both levels of every restore, including their
refusals — the traversal, the symlinked delivery directory, the username posted
into a user-level restore, the pre-clean pointed at a home directory. It ends
with the privilege probe that produced the table above.

---

## Uninstalling

```sh
sh /usr/local/directadmin/plugins/borg/scripts/uninstall.sh
```

There is very little to undo: the plugin only ever read the repository. What it
does clear is what versions before 2.0 could install back when they still ran
backups — a cron schedule and a DirectAdmin hook, both of which would otherwise
keep firing at commands this version no longer has.

It deliberately leaves `/var/lib/directadmin-borg/`, the borg repository, and
whatever actually writes to that repository alone — deleting a customer's only
backup because a plugin was removed is not a decision an uninstaller gets to
make. Remove them by hand when you mean it.

---

## Licence

MIT.
