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
  at any backup point and restore files themselves, without a support ticket.

Why split it this way: backup scripts are already solved, usually by something
older and more carefully tuned than a control-panel plugin. Restores are the
part that happens under pressure, at two in the morning, by whoever is on call.
That is the part worth putting a UI on.

Built against the [DirectAdmin Borg
documentation](https://docs.directadmin.com/directadmin/backup-restore-migration/borg.html)
and the [plugin API](https://docs.directadmin.com/developer/plugins/structure.html).

---

## Why the plugin runs as root

`plugin.conf` sets `admin_run_as=root` and `user_run_as=root`. This is a
requirement, not a convenience, and it is worth being explicit about because the
obvious alternative — run borg as the `admin` account, perhaps putting borg and
`admin` in a shared group — does not work.

The test suite settles this empirically rather than by assertion: it takes the
same backup twice against identical data, once as root and once as `admin`, and
compares the archives (`test/lib/PrivilegeProbe.php`). The result:

| | as `admin` | as `root` |
|---|---|---|
| Execute `/usr/bin/borg` | yes | yes |
| Create and own a repository | yes | yes |
| Read `/home/<user>/…` (mode `0711`) | **no** | yes |
| Entries captured from a two-customer `/home` | **5** | **32** |
| Exit code | **1 (warning)** | 0 |

A shared group changes nothing, because executing borg was never the problem:
`/usr/bin/borg` is already mode `0755`. The problem is *reading the data*.
DirectAdmin gives each customer home directory mode `0711`, so `admin` cannot
traverse into it; `/etc/shadow`, mail spools and `/usr/local/directadmin/conf`
are equally out of reach.

The dangerous part is the exit code. borg treats unreadable files as a
**warning**, not an error, so a backup run as `admin` exits 1, looks like it
worked, and silently contains almost nothing. A cron job would never notice.

Running plugin code as root has been supported natively since DirectAdmin
**1.689**, which replaced the older set-uid wrapper approach.

Because the user-level page also runs as root, every path arriving from a
request is normalised and confined to the requesting account's home directory
before it reaches borg — twice: once in the page, and again in the worker at the
point of use (`src/Security/PathGuard.php`, `src/Security/Account.php`).

---

## Requirements

| | |
|---|---|
| DirectAdmin | 1.689 or newer (for `*_run_as=root`) |
| PHP | 8.1 or newer, as the CLI at `/usr/local/bin/php` |
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

**From the release tarball** (ships `vendor/`, so the server needs neither
composer nor network access):

Admin Level → Plugin Manager → Add Plugin → upload `borg.tar.gz`.

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
directory.

**From a source checkout**, build the tarball first:

```sh
make package      # -> dist/borg.tar.gz
```

### Upgrading from 1.x

2.0 removed the backup side entirely. Upload the new tarball as usual; nothing
in `/var/lib/directadmin-borg/` is touched, so the repository location and
passphrase carry over. `install.sh` removes two things a 1.x install could have
left behind, because both would keep firing at commands that no longer exist:

- `/etc/cron.d/directadmin-borg`, the plugin's own schedule
- `/usr/local/directadmin/scripts/custom/all_backups_post/borg-plugin.sh`

The retired settings (source paths, compression, retention, schedule) are
dropped from `config.json` the first time it is saved. **Your own backup script
and its cron entry are not touched** — the plugin never installed them and does
not know about them. If you were relying on the plugin to take backups, set up a
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

The location is verified before it is stored. The plugin runs `borg info`
against it and saves only if borg finds a repository there; otherwise it says so
and keeps the previous setting. There is no "initialise" button, deliberately —
a mistyped path that silently created a second, empty repository would look like
a working configuration right up until the moment someone needed a restore.

For an encrypted repository, store the passphrase in the same form. For a remote
one, set **SSH command** to whatever your backup script exports as `BORG_RSH`,
e.g. `ssh -i /root/.ssh/borg_hetzner -p 23`. Both are needed for the
verification to pass, so a missing key is caught now rather than during a
recovery.

Settings are stored in `/var/lib/directadmin-borg/config.json` (mode 0600),
outside the plugin directory, so a plugin update does not lose them.

### What gets backed up, and by what

Not by this plugin. Whatever writes to the repository decides what is in it —
typically a script like:

```sh
REPOSITORY=/mnt/bigstorage/borg/`hostname`

borg create -v --stats $REPOSITORY::'{fqdn}-{now:%Y-%m-%d_%H:%M}' \
    /home /etc /usr/local/directadmin /var/log --exclude /home/mysql

borg prune -v $REPOSITORY --prefix '{fqdn}-' --keep-daily=14 --keep-weekly=8
```

Two things there decide what you can restore, so they are worth checking:

- **`/home` must be included**, or there is nothing to give a customer back.
- **DirectAdmin's own per-user backups must be included.** Databases are not in
  a home directory; DirectAdmin dumps them into `/home/admin/admin_backups/`,
  which `/home` covers — but only once a DirectAdmin backup run has happened.
  Restoring a whole account needs both, from the same archive.

### What a borg "version" is

There are no version numbers. A borg repository holds **archives**, and each
archive is a complete, named snapshot of everything the source paths contained
at the moment it ran. Deduplication means a snapshot only stores the chunks that
changed, so a hundred daily archives cost far less than a hundred copies — but
each one still restores independently and in full. There is no chain of
increments to replay.

Two separate things carry the date, which is worth keeping straight:

- **The archive name** comes from the name template, e.g.
  `{hostname}-{now:%Y-%m-%d_%H:%M:%S}` → `srv01-2026-09-14_03:30:00`. It is only
  a label. Change the template and old archives keep their old names.
- **The creation timestamp** is recorded by borg itself, independent of the
  name, and cannot be spoofed by renaming.

This plugin sorts and displays by the recorded timestamp, not the name, so a
changed template never disturbs the ordering. The user-level page shows only
dates for that reason; the admin pages show both.

"Restore this borg version" therefore means "restore from this archive", and
because archives are independent snapshots you can restore a user's home
directory and their DirectAdmin backup from the *same* archive and know the two
are consistent with each other.

### Retention

Also not this plugin's. Retention is whatever your `borg prune` invocation says,
and the plugin will not prune, compact or delete an archive — there is no UI for
it and no code path to it. This is on purpose: the plugin cannot know which
archives in a shared repository belong to which producer, and deleting the wrong
one is not recoverable.

### Restores

**Admin** can restore any path from any archive to any destination. Restores
never overwrite in place: files are written below the chosen directory keeping
their full original path, so `/home/alice/x` lands at
`<destination>/home/alice/x`. Restoring straight onto `/`, `/etc`, `/usr`,
`/home` and similar is refused — stage it and move the files deliberately.

**Users** browse their own home directory at a chosen backup date and restore
into `/home/<user>/borg_restore/`, chowned back to them. Their live files are
never touched. Turn the whole feature off with **Repository → Let users restore
their own files**.

### Restoring a whole user

A home directory is only half an account. DirectAdmin keeps the rest — the
account's configuration and its database dumps — in its own per-user backup
under `/home/admin/admin_backups/<username>.tar.zst` (or `.tar.gz`, `.tar.bz2`,
`.tar`, depending on the compression configured when it ran).

Open an archive and use **Restore a whole user**: give a username, and the
plugin restores their home directory *and* that tarball together. The location
is configurable under **Backup → DirectAdmin backups**, and the plugin warns if
it is not covered by your source paths — otherwise the archives would look fine
while being unable to restore an account.

Because the tarballs live under `/home/admin`, they are admin-only. A customer's
self-service restore cannot see or fetch them.

#### Recovering a user deleted a week ago

The full sequence, because the order is not obvious and each step depends on the
one before:

1. **Archives** → pick the archive from before the deletion. The list shows the
   date each one was taken.
2. **Restore a whole user** → enter the username. The account is gone, so the
   home-directory restore is refused and you are prompted instead.
3. Click **Restore `<user>`'s DirectAdmin backup**. With *“Place it in
   /home/admin/admin_backups”* ticked (the default) the tarball goes straight
   back to where DirectAdmin reads it, with its original ownership — which
   DirectAdmin requires of those files. No copying or `chown` by hand.
4. **Admin Level → Restore Backups** → restore that tarball. *This* is what
   recreates the account, its databases and its DirectAdmin configuration. The
   plugin never creates accounts.
5. Back in **Archives → Restore a whole user**, enter the username again. Now
   that the account exists, tick **Restore to the original location** to put the
   home directory back in place.

#### Cleaning up a compromised site

A restore is an overlay: borg cannot delete during an extract, so a webshell the
attacker left behind survives one. For malware cleanup, tick **Delete the site
directory first** on the restore-a-user form. It removes the named directory
before extracting, so only what is in the archive comes back.

Delete the whole `domains/example.com`, not just `public_html`. Where
`public_html` is a symlink into a repository checkout beside it, removing the
link deletes the link and leaves the real files — malware included.

Deletion is confined to the account's home, refuses the home directory itself
(that would take mail, cron and SSH keys with it), and needs the username typed
back. It never follows a symlink out of the tree: the link is removed, whatever
it points at is not.

Files are only half of it. Databases and DirectAdmin configuration live in the
admin backup, so if the account was already compromised when that archive was
taken, restoring it restores the compromise. Pick an archive from before the
break-in, and rotate database, FTP and SSH credentials afterwards.

Step 5 is an overlay, not a mirror: files in the archive are written over what
is there now, and anything created since the backup is left alone. borg cannot
delete files during an extract. For a freshly recreated account the home is
effectively empty, so the result is exact.

Leave *“Restore to the original location”* unticked to get a staging copy under
`/home/admin/borg_restore/` instead and inspect it before committing.

#### If the account no longer exists

**This plugin never creates accounts.** If you name a user DirectAdmin does not
have, the home-directory restore is refused rather than guessed at — restoring
files into a home for an account that does not exist leaves orphaned data with
no owner.

Instead you are prompted, because the order matters and it is not obvious:

1. Restore **only** the DirectAdmin backup — an explicit button that appears at
   that point, so nothing happens on your behalf.
2. Recreate the account from that tarball with **Admin Level → Restore
   Backups**. This is what actually creates the user, with its databases and
   configuration.
3. Come back here and restore the home directory.

If the account exists but has no tarball in that archive, the home directory is
still restored and you are told plainly that the databases and configuration are
not included.

---

## Operating it from the shell

```sh
/usr/local/directadmin/plugins/borg/bin/console borg:status
```

Shows the borg version, the uid the plugin runs as, the configured repository
and what borg reports about it (id, encryption mode), recent archives and recent
jobs — the fastest way to find out whether the plugin can read the repository at
all, and whether last night's backup actually wrote anything.

| Command | Purpose |
|---|---|
| `borg:status` | Diagnostics |
| `borg:job <id>` | Runs one queued job; the detached worker behind restores and checks |

There is no scheduled-backup or hook command: this plugin is never the thing
that runs a backup.

---

## How it is put together

```
plugin.conf              admin_run_as=root, user_run_as=root, menu entries
bootstrap.php            SAPI/PHP-version guard, autoloader, umask
admin/, user/            DirectAdmin entry points (index.html, status.raw, menu.raw)
bin/console              Symfony Console app: detached worker, diagnostics
hooks/                   classic-skin menu fragments
src/
  Borg/                  borg CLI wrapper and read-only repository operations
  Config/                configuration, validation constraints
  Http/                  DirectAdmin request decoding, JSON status endpoint
  Job/                   job records, dispatch, execution (restore and check)
  Security/              path confinement, account resolution, CSRF
  Ui/                    page controllers
templates/               Twig templates (auto-escaped)
test/                    Docker harness and suite
```

State lives in `/var/lib/directadmin-borg/` — **outside** the plugin directory,
because DirectAdmin replaces the whole plugin tree on update and would otherwise
take the repository location, passphrase and job history with it. That is also
why the repository path is stored there rather than in the plugin's own
directory: an update must not silently leave the plugin pointing at nothing.

```
/var/lib/directadmin-borg/
  config.json      0600   repository location and restore settings
  passphrase       0600   borg passphrase, kept apart from the config
  csrf.key         0600   HMAC key for form tokens
  jobs/            0700   one JSON record per job
  logs/            0700   one log per job
  locks/           0700   repository lock
  cache/           0700   compiled templates
```

### Which Symfony components, and why

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

A backup outlives any HTTP request, so the UI records a job and starts
`bin/console borg:job <id>` detached. Only the job id crosses that boundary; the
worker reads its parameters from the job file, so no request data ever reaches a
command line. The launch double-forks through `sh` deliberately — Symfony's
`Process` destructor stops any child it still owns, which would kill the backup
the moment the request finished.

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
| `make cs` | Coding standards, report only |
| `make cs-fix` | Coding standards, apply |
| `make lint` | Parse-check PHP, compile every Twig template |
| `make test` | Full suite against real borg |

PHPStan runs at **level 8** with no baseline and no ignored errors — the
findings it raised were fixed rather than suppressed. Notably it caught a dead
branch (`substr()` cannot return `false` in PHP 8), a property left behind by a
refactor, and eight places where a nullable `Account` was dereferenced on the
strength of a guard several methods away.

Coding standards are PSR-12 plus the Symfony ruleset, with risky rules enabled:
`strict_comparison` and `strict_param` catch real bugs, not just layout.

All tooling runs in containers pinned to PHP 8.1, so results do not depend on
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

All images are AlmaLinux, because that is what DirectAdmin servers run, and borg
is installed the way a real server installs it:

```sh
dnf -y install epel-release
dnf -y install borgbackup
```

| Environment | PHP | borg | Why |
|---|---|---|---|
| `alma9` | 8.2 | 1.2.9 (EPEL 9) | The common production shape |
| `alma8` | 8.2 | **1.1.18** (EPEL 8) | Still widely deployed, and the only place borg 1.1 still ships |
| `alma9-borg14` | 8.2 | 1.4.5 (pip) | **Opt-in.** Not in the default run |

The spread across those two axes is the point. The commands a restore needs are
spelled the same on 1.1 as on 1.4, but that is an assertion, not an assumption:
`alma8` is what proves it against a real borg 1.1 rather than a stub. (Dropping
the backup side removed the flags that actually differed between them —
`--prefix` versus `--glob-archives`, and `compact`.)

PHP is 8.2 on both, which is what the servers run. The plugin still declares
8.1 as its floor, and that floor is held by `make lint` and `make stan`, which
run on 8.1 — so syntax newer than the minimum cannot slip in even though nothing
in the matrix runs it.

`alma9-borg14` is excluded from the default run on purpose: no EL repository
carries borg 1.4, so installing it means pip and a build toolchain, which is not
how anyone runs it in production. It is there for the day EPEL moves to 1.4, and
worth running before putting a server on a borg newer than EPEL ships.

Each image carries a `/home` with two customer accounts at DirectAdmin's `0711`
permissions, DirectAdmin-style admin backups, an sshd for remote-repository
tests, and a stub borg for version-branch tests. Each run installs the plugin
with the production `install.sh`, then drives the real entry points the way
DirectAdmin does — environment in, stdout out.

No DirectAdmin server is involved, and nothing outside the container is touched.

The repository is created and filled by the suite itself, shelling out to borg
directly, standing in for the server's own backup script. The plugin is only
ever pointed at the result — so if a change ever made the plugin start writing
archives, the archive counts would stop matching.

The suite covers path confinement and traversal, cross-account access through
both the page and the worker, CSRF, the DirectAdmin env-decoding path, template
escaping, repository locking, detached dispatch, secret handling and file
permissions — including the restore-a-user flow and its refusal to touch a home
directory for an account DirectAdmin does not have — and ends with the privilege
probe that produced the table above.

It also exercises the parts that are easy to leave untested because they need a
real environment:

- every admin form action end to end
- that a path holding no repository is **refused** rather than initialised, that
  an existing directory which is not a repository is refused too, and that
  neither leaves anything behind on disk or disturbs the stored location
- that the retired backup actions (`init_repository`, `save_backup`,
  `run_backup`, `run_prune`, `delete_archive`) are rejected outright, and that
  rejecting them touched nothing in the repository
- that every admin tab actually renders — these run on `php -n`, where a Twig
  filter needing mbstring or iconv fatals at render time and is invisible to
  `make lint`
- an **encrypted** repository: read, list, restore, that the passphrase never
  reaches a command line, and that an unreadable one is not saved as if fine
- a **remote** repository over `ssh://`, against an sshd in the container,
  including that a wrong `BORG_RSH` is caught at save time
- `uninstall.sh`, including clearing a schedule left behind by a 1.x install
- job retention, oversized directory listings, and log tailing past 64 KB

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
