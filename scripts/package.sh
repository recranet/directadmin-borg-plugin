#!/bin/sh
# Build a release tarball for the DirectAdmin plugin manager.
#
# The tarball ships vendor/ so the target server needs neither composer nor
# network access. Dependencies are resolved against PHP 8.1 (composer.json pins
# config.platform.php), matching the native /usr/local/bin/php on the target
# servers.
#
#   sh scripts/package.sh            # build dist/borg.tar.gz
#   sh scripts/package.sh /tmp/out   # build into another directory
set -eu

PLUGIN_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
OUT_DIR=${1:-"$PLUGIN_DIR/dist"}
STAGE=$(mktemp -d)

cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT

VERSION=$(sed -n 's/^version=//p' "$PLUGIN_DIR/plugin.conf" | head -n1)
[ -n "$VERSION" ] || { echo "ERROR: no version= in plugin.conf" >&2; exit 1; }

echo "==> Building borg $VERSION"

# ------------------------------------------------------------------ staging
mkdir -p "$STAGE/borg"

# Everything the plugin needs at runtime, and nothing else: the test harness,
# build tooling and VCS metadata have no business on a production server.
for item in plugin.conf bootstrap.php composer.json composer.lock \
            admin user bin hooks images src templates scripts; do
    [ -e "$PLUGIN_DIR/$item" ] || continue
    cp -a "$PLUGIN_DIR/$item" "$STAGE/borg/"
done

rm -f "$STAGE/borg/scripts/package.sh"

# ------------------------------------------------------------ dependencies
# Built inside the staging copy, so packaging never disturbs the working tree's
# vendor/ (which has the dev tools in it). --no-dev keeps phpstan and
# php-cs-fixer out of the shipped tarball.
echo "==> Installing runtime dependencies"
if command -v docker >/dev/null 2>&1; then
    docker run --rm -v "$STAGE/borg":/app -w /app composer:2 \
        composer install --no-dev --no-interaction --no-progress --optimize-autoloader
elif command -v composer >/dev/null 2>&1; then
    (cd "$STAGE/borg" && composer install --no-dev --no-interaction --no-progress --optimize-autoloader)
else
    echo "ERROR: neither docker nor composer is available to build vendor/." >&2
    exit 1
fi

[ -f "$STAGE/borg/vendor/autoload.php" ] || { echo "ERROR: vendor/ was not built." >&2; exit 1; }

# Match what install.sh will enforce, so the tarball is already correct.
find "$STAGE/borg" -type d -exec chmod 755 {} +
find "$STAGE/borg" -type f -exec chmod 644 {} +
chmod 755 "$STAGE/borg/admin/index.html" "$STAGE/borg/admin/status.raw" "$STAGE/borg/admin/menu.raw" \
          "$STAGE/borg/user/index.html" "$STAGE/borg/user/status.raw" "$STAGE/borg/user/menu.raw" \
          "$STAGE/borg/bin/console" \
          "$STAGE/borg/scripts/install.sh" "$STAGE/borg/scripts/uninstall.sh"

# ----------------------------------------------------------------- tarball
# Two hard rules from the DirectAdmin plugin manager, both easy to get wrong:
#
#   1. The members go in at the *root* of the archive. DirectAdmin extracts the
#      upload into the plugin directory it just created and then looks for
#      plugin.conf there, so a wrapping borg/ directory makes it refuse the
#      upload with "The following file is missing: plugin.conf".
#
#   2. The file has to be called borg.tar.gz. The name minus .tar.gz becomes the
#      plugin directory, and therefore the URL prefix -- admin/menu.raw and
#      user/menu.raw point at /CMD_PLUGINS_ADMIN/borg/ and /CMD_PLUGINS/borg/.
#      A versioned name would install the plugin as "borg-1.2.3" and every menu
#      link and image would 404. The version lives in plugin.conf, not here.
mkdir -p "$OUT_DIR"
TARBALL="$OUT_DIR/borg.tar.gz"

# Reproducible-ish: owned by root, sorted, and free of macOS metadata.
#
# COPYFILE_DISABLE only suppresses AppleDouble ._ files. bsdtar still records
# extended attributes -- com.apple.provenance is stamped on every file
# downloaded or copied on a recent macOS -- and GNU tar on the server then
# prints "Ignoring unknown extended header keyword" for each of them, which is
# several hundred lines of noise over a real install. --no-xattrs is the fix;
# it is a bsdtar flag, so the build tolerates a tar that does not know it.
NOXATTR=""
if tar --no-xattrs --version >/dev/null 2>&1; then
    NOXATTR="--no-xattrs"
fi

MEMBERS=$(cd "$STAGE/borg" && ls -A | sort)
# Deliberately unquoted: one tar member per word, and an optional flag. Nothing
# staged has a space in its name.
# shellcheck disable=SC2086
( cd "$STAGE/borg" && COPYFILE_DISABLE=1 tar $NOXATTR --owner=0 --group=0 -czf "$TARBALL" $MEMBERS )

# The mistake this guards against cost a round-trip to the Plugin Manager once.
tar -tzf "$TARBALL" | grep -qx 'plugin.conf' \
    || { echo "ERROR: plugin.conf is not at the root of $TARBALL." >&2; exit 1; }

echo ""
echo "Built $TARBALL ($(du -h "$TARBALL" | cut -f1)) -- version $VERSION"
echo ""
echo "Install on a DirectAdmin server:"
echo "  Admin Level -> Plugin Manager -> Add Plugin -> upload borg.tar.gz"
echo "  (upload it under exactly that name: it becomes the plugin directory)"
echo ""
echo "Or from a shell:"
echo "  mkdir -p /usr/local/directadmin/plugins/borg"
echo "  tar -xzf borg.tar.gz -C /usr/local/directadmin/plugins/borg"
echo "  sh /usr/local/directadmin/plugins/borg/scripts/install.sh"
