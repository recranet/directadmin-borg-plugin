#!/bin/sh
# Installer for the DirectAdmin Borg plugin.
#
# Run by the DirectAdmin plugin manager, or by hand:
#   sh /usr/local/directadmin/plugins/borg/scripts/install.sh
set -eu

PLUGIN_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DATA_DIR="${BORG_PLUGIN_DATA_DIR:-/var/lib/directadmin-borg}"

log() { printf '%s\n' "$*"; }
fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" = "0" ] || fail "install.sh must run as root."

# ---------------------------------------------------------------- PHP binary
# DirectAdmin plugin scripts run the CLI PHP that /usr/local/bin/php points at,
# which CustomBuild sets from php1_release and which is not necessarily the
# first php on PATH.
PHP_BIN=""
for candidate in /usr/local/bin/php /usr/local/php84/bin/php /usr/local/php83/bin/php \
                 /usr/local/php82/bin/php /usr/local/php81/bin/php /usr/bin/php; do
    if [ -x "$candidate" ]; then PHP_BIN="$candidate"; break; fi
done
[ -n "$PHP_BIN" ] || fail "No PHP CLI binary found. With CustomBuild: da build php_cli"

PHP_OK=$("$PHP_BIN" -n -r 'echo PHP_VERSION_ID >= 80100 ? "yes" : "no";' 2>/dev/null || echo no)
[ "$PHP_OK" = "yes" ] || fail "$PHP_BIN is older than PHP 8.1, which this plugin requires."
log "Using PHP: $PHP_BIN ($("$PHP_BIN" -n -r 'echo PHP_VERSION;'))"

# The plugin runs PHP with -n (no php.ini) so that a hardened CLI ini cannot
# disable proc_open; that also means extensions must be compiled in, not loaded.
for ext in json pcre; do
    if ! "$PHP_BIN" -n -r "exit(extension_loaded('$ext') ? 0 : 1);" 2>/dev/null; then
        fail "PHP extension '$ext' is not built into $PHP_BIN."
    fi
done

# Point every executable at the PHP we just found.
for script in "$PLUGIN_DIR"/admin/index.html "$PLUGIN_DIR"/admin/status.raw "$PLUGIN_DIR"/admin/menu.raw \
              "$PLUGIN_DIR"/user/index.html "$PLUGIN_DIR"/user/status.raw "$PLUGIN_DIR"/user/menu.raw \
              "$PLUGIN_DIR"/bin/console; do
    [ -f "$script" ] || continue
    sed -i.bak "1s|^#!.*|#!${PHP_BIN} -n|" "$script"
    rm -f "$script.bak"
done

# ------------------------------------------------------------- dependencies
# The release tarball ships vendor/ prebuilt, so composer is not required on the
# server. Only fall back to composer when installing from a source checkout.
if [ ! -f "$PLUGIN_DIR/vendor/autoload.php" ]; then
    if command -v composer >/dev/null 2>&1; then
        log "vendor/ is missing; running composer install"
        (cd "$PLUGIN_DIR" && composer install --no-dev --no-interaction --no-progress --optimize-autoloader)
    else
        fail "vendor/ is missing and composer is not installed. Use the release tarball, which ships vendor/."
    fi
fi

# -------------------------------------------------------------------- borg
BORG_BIN=""
for candidate in /usr/bin/borg /usr/local/bin/borg /bin/borg; do
    if [ -x "$candidate" ]; then BORG_BIN="$candidate"; break; fi
done

if [ -z "$BORG_BIN" ]; then
    log ""
    log "WARNING: borg is not installed. Install it before running a backup:"
    log "  dnf install -y epel-release && dnf install -y borgbackup   # RHEL/Alma/Rocky"
    log "  apt-get install -y borgbackup                              # Debian/Ubuntu"
    log ""
else
    log "Found borg: $BORG_BIN ($("$BORG_BIN" --version 2>/dev/null || echo 'version unknown'))"
fi

# ------------------------------------------------------------- permissions
# Plugin code runs as root, so nothing in the tree may be writable by anyone
# else: a writable file here would be a root shell for whoever owns it.
chown -R root:root "$PLUGIN_DIR" 2>/dev/null || true
find "$PLUGIN_DIR" -type d -exec chmod 755 {} +
find "$PLUGIN_DIR" -type f -exec chmod 644 {} +
chmod 755 "$PLUGIN_DIR"/admin/index.html "$PLUGIN_DIR"/admin/status.raw "$PLUGIN_DIR"/admin/menu.raw \
          "$PLUGIN_DIR"/user/index.html "$PLUGIN_DIR"/user/status.raw "$PLUGIN_DIR"/user/menu.raw \
          "$PLUGIN_DIR"/bin/console \
          "$PLUGIN_DIR"/hooks/all_backups_post.sh \
          "$PLUGIN_DIR"/scripts/install.sh "$PLUGIN_DIR"/scripts/uninstall.sh

# State lives outside the plugin tree so a plugin update cannot wipe the
# repository config, passphrase or job history.
mkdir -p "$DATA_DIR/logs" "$DATA_DIR/jobs" "$DATA_DIR/locks" "$DATA_DIR/cache"
chown -R root:root "$DATA_DIR"
chmod 700 "$DATA_DIR" "$DATA_DIR/logs" "$DATA_DIR/jobs" "$DATA_DIR/locks" "$DATA_DIR/cache"
# `[ -f x ] && chmod` would abort under `set -e` when the file is absent, which
# is the normal case on a fresh install.
if [ -f "$DATA_DIR/passphrase" ]; then chmod 600 "$DATA_DIR/passphrase"; fi
if [ -f "$DATA_DIR/config.json" ]; then chmod 600 "$DATA_DIR/config.json"; fi
if [ -f "$DATA_DIR/csrf.key" ]; then chmod 600 "$DATA_DIR/csrf.key"; fi

# ------------------------------------------------------------------ verify
"$PHP_BIN" -n "$PLUGIN_DIR/bin/console" borg:status >/dev/null 2>&1 || \
    log "NOTE: 'bin/console borg:status' did not exit cleanly yet; that is expected before a repository is configured."

log ""
log "Borg plugin installed."
log "  Plugin:    $PLUGIN_DIR"
log "  State:     $DATA_DIR"
log "  Diagnose:  $PLUGIN_DIR/bin/console borg:status"
log ""
log "Next: open Admin Level -> Borg Backup, set a repository, and initialise it."
log ""
log "NOTE: plugin.conf sets admin_run_as=root and user_run_as=root. This is"
log "      required, not a convenience: the 'admin' account cannot read"
log "      /home/<user> (mode 0711), /etc/shadow or the DirectAdmin config, so"
log "      a backup run as 'admin' exits with a warning and silently archives"
log "      almost nothing. Putting borg and admin in a shared group does not"
log "      help: the binary is already world-executable; the unreadable data is"
log "      the problem."
exit 0
