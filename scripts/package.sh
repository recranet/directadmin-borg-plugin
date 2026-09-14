#!/bin/sh
# Build a release tarball for the DirectAdmin plugin manager.
#
# The tarball ships vendor/ so the target server needs neither composer nor
# network access. Dependencies are resolved against PHP 8.1 (composer.json pins
# config.platform.php), matching the native /usr/local/bin/php on the target
# servers.
#
#   sh scripts/package.sh            # build dist/borg-<version>.tar.gz
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

# ------------------------------------------------------------ dependencies
# Resolved in a container so the build does not depend on the local PHP
# version; composer.json's platform pin keeps the result valid on PHP 8.1.
if command -v docker >/dev/null 2>&1; then
    docker run --rm -v "$PLUGIN_DIR":/app -w /app composer:2 \
        composer install --no-dev --no-interaction --no-progress --optimize-autoloader
elif command -v composer >/dev/null 2>&1; then
    (cd "$PLUGIN_DIR" && composer install --no-dev --no-interaction --no-progress --optimize-autoloader)
else
    echo "ERROR: neither docker nor composer is available to build vendor/." >&2
    exit 1
fi

# ------------------------------------------------------------------ staging
mkdir -p "$STAGE/borg"

# Everything the plugin needs at runtime, and nothing else: the test harness,
# build tooling and VCS metadata have no business on a production server.
for item in plugin.conf bootstrap.php composer.json composer.lock \
            admin user bin hooks images src templates vendor scripts; do
    [ -e "$PLUGIN_DIR/$item" ] || continue
    cp -a "$PLUGIN_DIR/$item" "$STAGE/borg/"
done

rm -f "$STAGE/borg/scripts/package.sh"

# Match what install.sh will enforce, so the tarball is already correct.
find "$STAGE/borg" -type d -exec chmod 755 {} +
find "$STAGE/borg" -type f -exec chmod 644 {} +
chmod 755 "$STAGE/borg/admin/index.html" "$STAGE/borg/admin/status.raw" "$STAGE/borg/admin/menu.raw" \
          "$STAGE/borg/user/index.html" "$STAGE/borg/user/status.raw" "$STAGE/borg/user/menu.raw" \
          "$STAGE/borg/bin/console" "$STAGE/borg/hooks/all_backups_post.sh" \
          "$STAGE/borg/scripts/install.sh" "$STAGE/borg/scripts/uninstall.sh"

# ----------------------------------------------------------------- tarball
mkdir -p "$OUT_DIR"
TARBALL="$OUT_DIR/borg-$VERSION.tar.gz"

# Reproducible-ish: owned by root, sorted, no macOS extended attributes.
( cd "$STAGE" && COPYFILE_DISABLE=1 tar --owner=0 --group=0 -czf "$TARBALL" borg )

echo ""
echo "Built $TARBALL ($(du -h "$TARBALL" | cut -f1))"
echo ""
echo "Install on a DirectAdmin server:"
echo "  Admin Level -> Plugin Manager -> Add Plugin -> upload borg-$VERSION.tar.gz"
echo ""
echo "Or from a shell:"
echo "  tar -xzf borg-$VERSION.tar.gz -C /usr/local/directadmin/plugins/"
echo "  sh /usr/local/directadmin/plugins/borg/scripts/install.sh"
