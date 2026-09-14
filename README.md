# DirectAdmin Borg Backup plugin

Manage [BorgBackup](https://www.borgbackup.org/) repositories, schedules and
restores from inside DirectAdmin.

- **Admin level** — configure the repository, choose what to back up, set a
  schedule, browse archives, prune, and restore anything to anywhere.
- **User level** — each customer can browse their own home directory as it was
  at any backup point and restore files themselves, without a support ticket.

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

Admin Level → Plugin Manager → Add Plugin → upload `borg-<version>.tar.gz`.

Or from a shell:

```sh
tar -xzf borg-1.2.0.tar.gz -C /usr/local/directadmin/plugins/
sh /usr/local/directadmin/plugins/borg/scripts/install.sh
```

`install.sh` finds a suitable PHP, rewrites the shebangs, verifies the version
and required extensions, sets ownership and permissions, and creates the state
directory.

**From a source checkout**, build the tarball first:

```sh
make package      # -> dist/borg-1.2.0.tar.gz
```

---

## Using it

### First run

1. **Repository** — set the location and encryption mode, and store a passphrase
   if the mode needs one.
   - local: `/backup/borg`
   - remote: `ssh://borgbackup@10.0.0.5:22/backups/$(hostname -f)`
   - remote (scp-style): `borgbackup@10.0.0.5:/backups/host`

   For a remote repository, set up key-based SSH from root to the backup user
   first, then point **SSH command** at the key:
   `ssh -i /root/.ssh/borg_ed25519 -o StrictHostKeyChecking=yes`.

2. **Initialise** — creates the empty repository. Safe to run against an
   existing one: borg refuses and nothing changes.

3. **Backup** — review the source paths, retention and schedule, then save.

4. **Overview → Back up now** — the first run is the slow one; borg deduplicates
   everything after it.

### What is backed up by default

```
/home
/etc
/usr/local/directadmin/conf
/usr/local/directadmin/data/users
```

Databases are **not** dumped by this plugin. DirectAdmin dumps them into its own
per-user backups under `/home/admin/admin_backups/`, which `/home` already
covers — but only if a DirectAdmin backup run has actually happened. Enable
**“Also run after DirectAdmin's own backups finish”** so a borg archive is taken
once those files exist, and the two stay in step.

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

Pruning runs after each backup, inside the same repository lock, scoped to the
configured **archive prefix** — so it can never delete archives another tool
wrote to the same repository. A rule set to `0` is disabled; with all three at
`0`, nothing is ever pruned.

With borg 1.2+, **compact** runs after pruning to actually reclaim the disk
space.

### Restores

**Admin** can restore any path from any archive to any destination. Restores
never overwrite in place: files are written below the chosen directory keeping
their full original path, so `/home/alice/x` lands at
`<destination>/home/alice/x`. Restoring straight onto `/`, `/etc`, `/usr`,
`/home` and similar is refused — stage it and move the files deliberately.

**Users** browse their own home directory at a chosen backup date and restore
into `/home/<user>/borg_restore/`, chowned back to them. Their live files are
never touched. Turn the whole feature off with **User restores → Let users
restore their own files**.

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

Shows the borg version, the uid the plugin runs as, the repository, the
schedule, recent archives and recent jobs — useful when the UI is not the
fastest way to find out why last night's backup failed.

Other commands (normally invoked by cron, the hook, or the UI):

| Command | Purpose |
|---|---|
| `borg:status` | Diagnostics |
| `borg:scheduled-backup` | What `/etc/cron.d/directadmin-borg` runs |
| `borg:job <id>` | Runs one queued job; the detached worker |
| `borg:hook-backup <trigger>` | Backup from a DirectAdmin hook, if enabled |

---

## How it is put together

```
plugin.conf              admin_run_as=root, user_run_as=root, menu entries
bootstrap.php            SAPI/PHP-version guard, autoloader, umask
admin/, user/            DirectAdmin entry points (index.html, status.raw, menu.raw)
bin/console              Symfony Console app: worker, cron, hook, diagnostics
hooks/                   all_backups_post.sh, classic-skin menu fragments
src/
  Borg/                  borg CLI wrapper and repository operations
  Config/                configuration, validation constraints
  Http/                  DirectAdmin request decoding, JSON status endpoint
  Job/                   job records, dispatch, execution
  Schedule/              /etc/cron.d management
  Security/              path confinement, account resolution, CSRF
  Ui/                    page controllers
templates/               Twig templates (auto-escaped)
test/                    Docker harness and suite
```

State lives in `/var/lib/directadmin-borg/` — **outside** the plugin directory,
because DirectAdmin replaces the whole plugin tree on update and would otherwise
take the repository config, passphrase and job history with it.

```
/var/lib/directadmin-borg/
  config.json      0600   settings
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
| `symfony/validator` | Configuration rules as declarative constraints, including a custom `CronField` constraint, since those values are written into `/etc/cron.d`. |
| `symfony/lock` | The repository lock, so a scheduled run overlapping a manual one becomes a clear "already running" instead of a borg lock error. |
| `symfony/finder` | Job listing and recursive ownership fixes. |
| `symfony/http-foundation` | Rebuilding a real `Request` from DirectAdmin's environment variables, for typed input access. |
| `symfony/console` | The worker, cron entry point, hook and diagnostics. |
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

## Testing

```sh
make test
```

Builds a container pinned to **PHP 8.1** (matching the native CLI on the target
servers, so 8.2+ syntax cannot sneak in) with a real borg and a `/home`
containing two customer accounts at DirectAdmin's `0711` permissions. It then
installs the plugin with the production `install.sh` and runs 268 checks,
driving the real entry points the way DirectAdmin does — environment in, stdout
out.

No DirectAdmin server is involved, and nothing outside the container is touched.

The suite covers path confinement and traversal, cross-account access through
both the page and the worker, CSRF, the DirectAdmin env-decoding path, template
escaping, repository locking, cron file generation, prune scoping, detached
dispatch, secret handling and file permissions — including the restore-a-user
flow and its refusal to touch a home directory for an account DirectAdmin does
not have — and ends with the privilege probe that produced the table above.

---

## Uninstalling

```sh
sh /usr/local/directadmin/plugins/borg/scripts/uninstall.sh
```

Removes the schedule. It deliberately leaves `/var/lib/directadmin-borg/` and
the borg repository alone — deleting a customer's only backup because a plugin
was removed is not a decision an uninstaller gets to make. Remove them by hand
when you mean it.

---

## Licence

MIT.
