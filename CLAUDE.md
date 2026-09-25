# CLAUDE.md

Guidance for working in this repository. The README is the reference for what
the plugin does and why; this file is the part that is easy to get wrong.

## Commands

```sh
make check                  # stan + cs + lint
test/docker-test.sh         # full suite in Docker (AlmaLinux 9 and 8, real borg)
make stan                   # PHPStan level 8
make cs-fix                 # apply coding standards (make cs to report only)
make lint                   # parse-check PHP and compile every Twig template
make package                # build dist/borg.tar.gz
sh scripts/deploy.sh host…  # deploy dist/borg.tar.gz as it stands
```

Run `make check` and `test/docker-test.sh` before committing.
`test/docker-test.sh alma9` runs one environment when the full matrix is too
slow. Every tool runs in Docker; nothing needs installing locally.

There is no `make test` or `make deploy`, on purpose: the user wants to see
exactly what runs, so the suite and deploys are always the scripts themselves,
called directly.

**Claude does not run make at all** — `.claude/settings.json` denies it,
because a make target runs whatever it contains (ssh included) without a
permission prompt. The Makefile is for people. Claude runs the commands it
wraps instead, in full:

```sh
PHP='docker run --rm -v "$PWD":/app -w /app -e PHP_CS_FIXER_IGNORE_ENV=1 php:8.2-cli-bookworm php'
$PHP vendor/bin/phpstan analyse --memory-limit=1G                  # stan
$PHP vendor/bin/php-cs-fixer fix --dry-run --diff                  # cs (drop --dry-run --diff to fix)
$PHP test/lint-templates.php                                       # templates
docker run --rm -v "$PWD":/app -w /app composer:2 composer audit --locked
sh scripts/package.sh                                              # package
```

`scripts/deploy.sh` is on the ask list, so a deploy always prompts even though
the ssh it runs happens inside the script. Package first, check the version in
the tarball (`tar -xzOf dist/borg.tar.gz plugin.conf`), then deploy with the
hosts spelled out.

## Hard constraints

**PHP 8.2 is the floor.** DirectAdmin executes plugin scripts on whatever
`/usr/local/bin/php` points at, which CustomBuild lets an administrator pin. No
8.3+ syntax — no typed class constants, no `#[Override]`, no property hooks.
`make stan` and `make lint` run on 8.2 and are what hold the line. The floor
moved up from 8.1 once every server in the fleet was verified on 8.2 or newer;
it is what lets the plugin be on Symfony 7.4, since no 7.x branch accepts 8.1.

**Scripts run with `php -n`**, so no `php.ini` and no guaranteed extensions.
Nothing may depend on an extension that is not compiled in — this is why the
archive index is a sorted flat file with a binary search rather than SQLite.

**The plugin never writes to the borg repository.** No `init`, no `create`, no
`prune`, no `delete`. The repository belongs to whatever already backs the
server up. `break-lock` and `extract` are the only non-read operations, and
both write outside the repository. The test suite fills the repository itself,
shelling out to borg directly, so if plugin code ever starts writing archives
the archive counts stop matching.

**Everything runs as root**, at user level too (`plugin.conf`). So every path
arriving in a request is confined twice: once in the page, and again in the
worker at the point of use, re-derived from the job file rather than trusted.
`src/Security/PathGuard.php` is lexical on purpose — it never touches the
filesystem, because the same rules apply to paths that only exist inside an
archive. It therefore cannot see a symlink.

**Root never writes, deletes, chmods or chowns inside a customer's home.**
Everything that has to goes through `src/Security/AccountFilesystem.php`,
which runs the operation as the account (`setpriv`, no supplementary groups,
an exact environment). Checking the path first does not hold: the customer
owns every directory below their home and can swap one for a symlink between
the check and the write, and PHP has no `openat`/`O_NOFOLLOW` to do it safely
as root. Symfony's Filesystem is no help here — it is `is_link()` then an
operation on the same path string. borg 1.1 and 1.2 extract straight through a
symlinked parent, so restores into a home are `borg export-tar` or
`extract --stdout` piped into a process running as the account
(`BorgRunner::runInto()`). A `realpath()` or `is_link()` check near a write is
an early refusal with a clear message, never the guard.

## Conventions

- **Comments explain the decision, not the mechanism.** Why this and not the
  obvious alternative, and what goes wrong if it changes. Match the density of
  the surrounding code — it is high, deliberately.
- **Commit messages are prose**, several paragraphs, leading with the problem
  and spending most of their length on the part worth reading. End with the
  check count and the environments it passed on.
- **Version bumps live in release commits only** (`Release 2.1.0: ...`), never
  in a feature commit. `plugin.conf` carries the version; the tarball name does
  not.
- **Config keys are never renamed**, only relabelled — an existing install's
  `config.json` has to keep working.
- Small final classes with docblocks. A hand-rolled container in `Plugin.php`.

## Tests

`test/run.php` is a custom harness, not PHPUnit, because half of what it
asserts is process- and permission-level behaviour driven through the real
DirectAdmin entry points — environment in, stdout out.

- Assertions against page output go through Twig, which **auto-escapes**.
  `No DirectAdmin backup for "admin"` is `&quot;admin&quot;` in the HTML.
- Tests run in order against shared fixtures (`test/docker/setup-fixtures.sh`)
  and mutate the live `/home`. A new group inherits whatever earlier ones left
  behind; clean up after anything a later group would trip over, and add new
  paths to `Harness::reset()`.
- Prove the refusal, not just the happy path: the traversal, the symlink, the
  cross-account path, the job file that asks for the dangerous combination.
