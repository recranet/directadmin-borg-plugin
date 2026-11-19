# DirectAdmin Borg Backup plugin

Browse and restore from the [BorgBackup](https://www.borgbackup.org/) repository
your server already backs up to, from inside DirectAdmin.

**This plugin does not take backups.** It creates no repository, writes no
archive, prunes nothing and installs no schedule. Whatever fills the repository
(a cron script, a DirectAdmin hook, borgmatic) keeps doing that. The plugin is
the restore end.

- **Admin level**: browse any archive and restore anything, including a whole
  account.
- **User level**: customers browse their own home as it was at any backup point,
  restore their website or mail, and get the DirectAdmin backup holding their
  databases, without a support ticket.

Built against the [DirectAdmin Borg
documentation](https://docs.directadmin.com/directadmin/backup-restore-migration/borg.html)
and the [plugin API](https://docs.directadmin.com/developer/plugins/structure.html).

## Requirements

| | |
|---|---|
| DirectAdmin | 1.689 or newer (for `*_run_as=root`) |
| PHP | 8.2 or newer, the CustomBuild CLI at `/usr/local/bin/php` |
| borg | 1.1–1.4 (borg 2.x is not supported) |

```sh
dnf install -y epel-release && dnf install -y borgbackup   # RHEL / Alma / Rocky
apt-get install -y borgbackup                              # Debian / Ubuntu
```

Scripts run with `php -n`, so a hardened CLI `php.ini` cannot disable
`proc_open` underneath the plugin.

## Why it runs as root

`plugin.conf` sets `admin_run_as=root` and `user_run_as=root`. Running borg as
`admin` does not work: customer homes are `0711`, so `admin` cannot read them.
Borg treats unreadable files as a warning, so such a backup exits 1, looks
fine, and contains almost nothing. The suite proves it
(`test/lib/PrivilegeProbe.php`):

| | as `admin` | as `root` |
|---|---|---|
| Read `/home/<user>/…` (mode `0711`) | **no** | yes |
| Entries captured from a two-customer `/home` | **5** | **32** |
| Exit code | **1 (warning)** | 0 |

Because user-level pages run as root too:

- Every request path is confined to the account's home twice, in the page and
  again in the worker (`src/Security/PathGuard.php`).
- Root never writes into a customer's home. Restores, deletes and deliveries
  run as the account (`src/Security/AccountFilesystem.php`), so a symlink the
  customer plants reaches nothing they could not reach themselves.

## Installing

Admin Level → Plugin Manager → Add Plugin → upload `borg.tar.gz`. The tarball
ships `vendor/`, so the server needs no composer or network.

Upload it under exactly that name. DirectAdmin names the plugin directory after
the file, and every menu link uses `/CMD_PLUGINS_ADMIN/borg/`. The version is in
`plugin.conf`.

From a shell:

```sh
mkdir -p /usr/local/directadmin/plugins/borg
tar -xzf borg.tar.gz -C /usr/local/directadmin/plugins/borg
sh /usr/local/directadmin/plugins/borg/scripts/install.sh
```

`install.sh` finds PHP, rewrites the shebangs, checks version and extensions,
sets permissions and creates the state directory.

### Deploying to several servers

```sh
make package                       # build dist/borg.tar.gz
sh scripts/deploy.sh host1 host2   # deploy it as it stands
```

Per host, `deploy.sh`:

1. Refuses before touching anything if PHP is below 8.2, borg is missing, or a
   job is running.
2. Extracts to `plugins/borg.new`, moves the running plugin to
   `/root/borg.bak-<version>`, swaps the new one in, runs `install.sh`. The
   backup goes to `/root` because DirectAdmin lists any directory under
   `plugins/` with a `plugin.conf` as a plugin.
3. Verifies the version, that `config.json` is unchanged, that `borg:status`
   reads the repository, and that the admin and user pages render.

Roll back by moving the copy back:

```sh
ssh root@host 'cd /usr/local/directadmin/plugins &&
               rm -rf borg && mv /root/borg.bak-2.3.0 borg'
```

### Upgrading from 1.x

2.0 removed the backup side. Settings in `/var/lib/directadmin-borg/` carry
over. `install.sh` removes the 1.x cron entry (`/etc/cron.d/directadmin-borg`)
and hook (`scripts/custom/all_backups_post/borg-plugin.sh`). **If the plugin was
taking your backups, set up your own `borg create` cron job first.**

## Configuring

Under **Repository**, enter the location your backups already go to:

- local: `/mnt/bigstorage/borg/$(hostname)`
- remote: `ssh://borgbackup@10.0.0.5:22/backups/$(hostname -f)`
- scp-style: `borgbackup@10.0.0.5:/backups/host`

Copy it from your backup script. Repositories are usually per host, so
`/mnt/bigstorage/borg` is often the parent of the one you want. The plugin runs
`borg info` and only saves if it finds a repository. There is no "initialise"
button: a typo must not create an empty second repository.

Also set the passphrase for an encrypted repository, and **SSH command** (your
script's `BORG_RSH`, e.g. `ssh -i /root/.ssh/borg_hetzner -p 23`) for a remote
one.

### What the backup must include

- **`/home`**, or there is nothing to give back.
- **DirectAdmin's per-user backups** (`/home/admin/admin_backups/` by default),
  because databases are not in a home. Tell the plugin where they are under
  **Repository → DirectAdmin backups directory**. Files are named
  `<level>.<creator>.<user>.tar.zst` or `<user>.tar.zst`.

Retention is whatever your `borg prune` says. The plugin never prunes, compacts
or deletes an archive.

### The backup window

A plugin read holds borg's repository lock. `borg create` waits one second for
it by default, then fails for every account. So under **Repository → Pause
restores during the server backup** (00:00–07:00 by default, server time) no
restore, index or check starts. Running jobs finish, so start the window well
before the backup. Clear both times to switch it off.

## Using it

### Archives

Each archive is a complete snapshot. Restoring one replays nothing, and a home
directory and a DirectAdmin backup from the same archive are consistent. The
plugin sorts by borg's creation timestamp, not the archive name.

### Browsing

`borg list` walks the whole archive for every directory, so the plugin scans an
archive **once** in the background and writes an index. After that each
directory opens in about 150 ms. Customers trigger the same scan with **Prepare
this backup**; customer scans run one at a time across the server.

By default the index holds directories and symlinks only (files outnumber
directories five to one). Restoring a directory still restores everything in it.
Turn on **Index individual files as well as directories** to pick single files.

### Restores

Opening an archive lists its **accounts**. Accounts DirectAdmin no longer has
are marked. Per account:

- **Restore Domains**: back into `/home/<user>/domains`, in place.
- **Restore Email**: back into `/home/<user>/imap`, in place.
- **Restore Databases**: the DirectAdmin backup, into `/home/<user>/backups`.
- **Restore the whole account** (admin): all of the above.
- **Browse files**: pick single paths.

Customers get the same screen for their own account. Both levels ask for a tick
before overwriting live files. Turn user-level restores off with **Repository →
Let users restore their own files**.

Restored files are owned by the account and its group. ACLs and extended
attributes are not restored. Restoring `imap` is an overlay: new mail stays,
deleted mail comes back, and mail clients may re-download.

### Restore vs. pre-clean

A restore **overwrites**: files in the backup replace live ones, everything else
stays. After a hack that leaves the webshell in place.

**Delete `/home/<user>/domains` first** empties the directory, then restores.
Everything added since is gone. It needs a tick and the typed account name, at
both levels. The deletion runs as the account and never touches the home itself.

- Delete all of `domains/example.com`, not `public_html`. That is often a
  symlink, and deleting it leaves the real files.
- Pick an archive from before the break-in, and rotate database, FTP and SSH
  passwords afterwards.

### Databases

The plugin loads no SQL. **Restore Databases** puts the account's own
DirectAdmin backup in `/home/<user>/backups`, owned by the account. Then, as
that user: **Create/Restore Backups → Restore Backups**, pick the file, tick
**Databases**.

- The tarball stays there and counts against quota. Delete it afterwards.
- It is written under a hidden name and renamed when complete, so DirectAdmin
  never offers a half-written file.
- Turn it off with **Repository → Use DirectAdmin's own per-user backups**.

### A deleted account

The plugin never creates accounts. For a user DirectAdmin no longer has:

1. **Archives** → pick an archive from before the deletion.
2. **Restore a whole user** → enter the username. The home restore is refused.
3. **Restore `<user>`'s DirectAdmin backup**, with *Place it in
   /home/admin/admin_backups* ticked.
4. **Admin Level → Restore Backups** → restore that tarball. This recreates the
   account and its databases.
5. **Restore a whole user** again, with **Restore to the original location**.

If a restore setting changes after an archive was indexed, the archive is marked
**needs refresh**.

## Shell

```sh
/usr/local/directadmin/plugins/borg/bin/console borg:status
```

Shows the borg version, the uid, the repository, recent archives and recent
jobs. The other command, `borg:job <id>`, is the detached worker.

## Layout

```
plugin.conf      admin_run_as=root, user_run_as=root, menu entries
bootstrap.php    PHP-version guard, autoloader, umask
admin/, user/    DirectAdmin entry points
bin/console      Symfony Console: worker, diagnostics
src/Borg/        borg wrapper, read-only operations, archive index
src/Config/      configuration and validation
src/Http/        request decoding, JSON status endpoint
src/Job/         job records, dispatch, restores
src/Security/    path confinement, accounts, CSRF
src/Ui/          page controllers
templates/       Twig (auto-escaped)
test/            Docker harness and suite
```

State lives outside the plugin, so updates keep it:

```
/var/lib/directadmin-borg/
  config.json   0600   repository and restore settings
  passphrase    0600   borg passphrase
  csrf.key      0600   form token key
  jobs/ logs/   0700   one record and log per job
  locks/        0700   repository lock
  cache/        0700   compiled templates
  index/        0700   archive indexes (~67 MB per 2.5M-entry archive; disposable)
```

The index is a sorted tab-separated file searched by byte offset, not SQLite,
because `php -n` guarantees no extensions.

Restores and checks run as `bin/console borg:job <id>`, started detached. Only
the job id crosses over; the worker reads everything else from the job file. The
UI polls `status.raw`, which at user level answers `404` for other users' jobs.

## Development

```sh
make check                         # phpstan level 8 + cs + lint
test/docker-test.sh                # alma9 and alma8
test/docker-test.sh alma9          # one environment
test/docker-test.sh alma9-borg14   # opt-in: borg 1.4 via pip
```

| Environment | PHP | borg |
|---|---|---|
| `alma9` | 8.2 | 1.2.9 (EPEL 9) |
| `alma8` | 8.2 | 1.1.18 (EPEL 8) |
| `alma9-borg14` | 8.2 | 1.4.5 (pip) |

All tooling runs in Docker on PHP 8.2, the floor. Symfony is on 7.4 LTS: 7.x
needs PHP 8.2 and 8.0 needs 8.4. `scripts/package.sh` installs `vendor/` with
`--no-dev`.

The suite fills the repository itself with borg, then drives the real entry
points (environment in, stdout out) against a `/home` with two customers. It
covers path confinement, cross-account access, CSRF, escaping, locking,
encrypted and remote repositories, the archive index against real borg, and the
refusals: traversal, symlinks, a username posted into a user-level restore, a
pre-clean aimed at a home.

## Uninstalling

```sh
sh /usr/local/directadmin/plugins/borg/scripts/uninstall.sh
```

Removes leftover 1.x cron and hook entries. Leaves `/var/lib/directadmin-borg/`,
the repository and your backup script alone.

## Licence

MIT.
