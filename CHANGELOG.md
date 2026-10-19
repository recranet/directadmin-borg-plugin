# Changelog

What changed per release, for whoever upgrades a server. The release commit
(`Release x.y.z: ...`) carries the full reasoning; this is the short version.

## 2.5.2 — 2026-10-19

- **Restores work on servers running Imunify360.** Every restore on
  rn-webhost-hike failed at once with `fclose(): Argument #1 ($stream) must be
  of type resource, null given`. The job worker was started as
  `php bin/console`, which bypasses the script's `php -n` shebang, so it loaded
  the server's php.ini and Imunify360's `i360` extension with it. `i360` makes
  `fopen()` of /etc/passwd return null. The worker now runs on `php -n` like
  every page, so nothing php.ini loads or disables — `i360`, or CustomBuild's
  secure_php `disable_functions` — reaches it.
- A passwd file that cannot be opened is now an error naming the file, instead
  of a TypeError from `fclose()`.

config.json carries over untouched.

## 2.5.1 — 2026-09-25

- The installed version is in the footer of every page.

## 2.5.0 — 2026-09-25

- **Restores into a home are written by the account, not root**: in-place
  restores, restores into the account's own directory, the pre-clean, and the
  Databases tarball. Restored files belong to the account and its own group, so
  mail under imap/ comes back in the account's group. ACLs and extended
  attributes are not restored. Needs `setpriv` and `tar`.
- **Backup window.** No restore, index or check starts during the server's own
  backup. Repository → Pause restores during the server backup, 00:00–07:00 by
  default in the server's timezone. A job already running is left to finish.

## 2.4.0 — 2026-09-22

- PHP 8.2 is the minimum (`php1_release` in CustomBuild). install.sh refuses
  older versions.
- Symfony 7.4 LTS. No behaviour changes.

## 2.3.0 — 2026-09-21

- User Level no longer scans a backup on page load. It offers "Prepare this
  backup" as a job, one customer scan at a time per server.
- Restore Databases is offered at User Level.
- Both levels ask before overwriting live files, and both offer the pre-clean
  (tick plus typed account name).

## 2.2.1 — 2026-09-21

- The admin pages say "backup" instead of "archive". Wording only; no keys or
  parameters renamed.

## 2.2.0 — 2026-09-18

- **Restore Databases** puts the account's DirectAdmin backup tarball in
  `/home/<user>/backups`, for DirectAdmin's own restore screen to import.
- User Level gets the same restore panel as Admin Level.

## 2.1.0 — 2026-09-17

- Customer restores go back in place instead of into `~/borg_restore`. The
  staging directory and its setting are gone.

## 2.0.0 — 2026-09-17

- **Restore-only.** No init, backup, prune, compact, schedule or hook; the
  repository belongs to whatever already fills it. Setting the repository
  verifies it with `borg info`.
- The tarball installs: members at the archive root, named `borg.tar.gz`.
- Pages render with a proper font and width inside Evolution.

## 1.3.0 – 1.3.3 — 2026-09-14

- Test suite coverage for every form action, encrypted and remote repositories,
  and borg 1.1, run on AlmaLinux 8 and 9 with borg from EPEL.
- The repository lock is released before a job is marked finished.

## 1.2.0 – 1.2.1 — 2026-09-14

- Optional pre-clean: delete a directory in the account's home before restoring
  it, so files an attacker added do not survive the restore.
- PHPStan level 8 and PHP-CS-Fixer.

## 1.1.0 — 2026-09-14

- Restore a user's DirectAdmin admin backup alongside their home, and restore
  in place.
