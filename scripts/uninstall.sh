#!/bin/sh
# Uninstaller for the DirectAdmin Borg plugin.
#
# Removes the schedule. Backup archives and plugin state are left alone:
# deleting a customer's only backup because a plugin was removed is not a
# decision this script gets to make.
set -eu

DATA_DIR="${BORG_PLUGIN_DATA_DIR:-/var/lib/directadmin-borg}"
CRON_FILE="${BORG_PLUGIN_CRON_FILE:-/etc/cron.d/directadmin-borg}"

[ "$(id -u)" = "0" ] || { printf 'ERROR: uninstall.sh must run as root.\n' >&2; exit 1; }

if [ -f "$CRON_FILE" ]; then
    rm -f "$CRON_FILE"
    printf 'Removed schedule: %s\n' "$CRON_FILE"
fi

cat <<TEXT

The plugin is uninstalled.

Left in place on purpose:
  $DATA_DIR   (repository config, passphrase, job history)
  your borg repository and all its archives

To remove the plugin state as well:
  rm -rf $DATA_DIR

The repository itself must be deleted manually with "borg delete".
TEXT
exit 0
