#!/bin/sh
# Uninstaller for the DirectAdmin Borg plugin.
#
# The plugin only ever read the repository, so there is very little to undo.
# What it does clean up is what versions before 2.0 could install when they
# still ran backups: a cron schedule and a DirectAdmin hook. Archives and plugin
# state are left alone: deleting a customer's only backup because a plugin was
# removed is not a decision this script gets to make.
set -eu

DATA_DIR="${BORG_PLUGIN_DATA_DIR:-/var/lib/directadmin-borg}"
CRON_FILE="${BORG_PLUGIN_CRON_FILE:-/etc/cron.d/directadmin-borg}"
HOOK_FILE=/usr/local/directadmin/scripts/custom/all_backups_post/borg-plugin.sh

[ "$(id -u)" = "0" ] || { printf 'ERROR: uninstall.sh must run as root.\n' >&2; exit 1; }

if [ -f "$CRON_FILE" ]; then
    rm -f "$CRON_FILE"
    printf 'Removed the schedule left by an older version: %s\n' "$CRON_FILE"
fi

if [ -f "$HOOK_FILE" ]; then
    rm -f "$HOOK_FILE"
    printf 'Removed the backup hook left by an older version: %s\n' "$HOOK_FILE"
fi

cat <<TEXT

The plugin is uninstalled.

Left in place on purpose:
  $DATA_DIR   (repository location, passphrase, job history)
  your borg repository and all its archives
  whatever backup script or cron job actually writes to that repository

To remove the plugin state as well:
  rm -rf $DATA_DIR
TEXT
exit 0
