#!/bin/sh
# DirectAdmin hook: runs after an "all backups" run finishes.
#
# Starts a borg archive so the DirectAdmin backup files that were just written
# (and the database dumps under /home/*/backups) are captured. Does nothing
# unless "Also run after DirectAdmin's own backups finish" is enabled in the
# plugin settings.
#
# DirectAdmin runs hooks as root and ignores their exit code; this script stays
# quiet and always succeeds so it can never fail the backup run it is attached
# to.
set -eu

PLUGIN_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
CONSOLE="$PLUGIN_DIR/bin/console"

[ -x "$CONSOLE" ] || exit 0

"$CONSOLE" borg:hook-backup all_backups_post >/dev/null 2>&1 || true
exit 0
