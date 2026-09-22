# CLAUDE.md

Guidance for working in this repository. The README is the reference for what
the plugin does and why; this file is the part that is easy to get wrong.

## Commands

```sh
make check     # stan + cs + lint + test -- run this before committing
make test      # full suite in Docker (AlmaLinux 9 and 8, real borg)
make stan      # PHPStan level 8
make cs-fix    # apply coding standards (make cs to report only)
make lint      # parse-check PHP and compile every Twig template
make package   # build dist/borg.tar.gz
```

`test/docker-test.sh alma9` runs one environment when the full matrix is too
slow. Every tool runs in Docker; nothing needs installing locally.

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
archive. It therefore cannot see a symlink; anywhere the worker writes into a
directory a customer owns, resolve the path and check it again
(`JobRunner::prepareDelivery()` is the worked example).

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
