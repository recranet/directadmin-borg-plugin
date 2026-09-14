#!/bin/bash
# Test driver for the DirectAdmin Borg plugin.
#
# Copies the source to the real plugin path inside the container, installs it
# with the production installer, then runs the suite. Nothing here touches a
# DirectAdmin server.
set -euo pipefail

SRC_DIR=${SRC_DIR:-/plugin}
PLUGIN_DIR=/usr/local/directadmin/plugins/borg

if [ "$(id -u)" != "0" ]; then
    echo "These tests must run as root inside the test container." >&2
    echo "Use: test/docker-test.sh" >&2
    exit 1
fi

echo "==> PHP $(php -r 'echo PHP_VERSION;') / $(borg --version 2>/dev/null || echo 'borg missing')"

echo "==> Staging the plugin into $PLUGIN_DIR"
rm -rf "$PLUGIN_DIR"
mkdir -p "$(dirname "$PLUGIN_DIR")"
cp -a "$SRC_DIR" "$PLUGIN_DIR"
rm -rf "$PLUGIN_DIR/.git" "$PLUGIN_DIR/test/docker"

if [ ! -f "$PLUGIN_DIR/vendor/autoload.php" ]; then
    echo "vendor/ is missing. Run: docker run --rm -v \"\$PWD\":/app -w /app composer:2 composer install" >&2
    exit 1
fi

echo "==> Running the production installer"
sh "$PLUGIN_DIR/scripts/install.sh"

# The plugin's own code must parse on the PHP this container pins (8.1), which
# is the native /usr/local/bin/php on the target servers. vendor/ is excluded:
# it is upstream code, already constrained by composer's platform setting.
echo "==> Linting plugin sources on PHP $(php -r 'echo PHP_VERSION;')"
find "$PLUGIN_DIR/src" "$PLUGIN_DIR/test" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
for file in "$PLUGIN_DIR"/bootstrap.php "$PLUGIN_DIR"/bin/console \
            "$PLUGIN_DIR"/admin/index.html "$PLUGIN_DIR"/admin/status.raw "$PLUGIN_DIR"/admin/menu.raw \
            "$PLUGIN_DIR"/user/index.html "$PLUGIN_DIR"/user/status.raw "$PLUGIN_DIR"/user/menu.raw; do
    php -l "$file" >/dev/null
done
echo "    all plugin sources parse on PHP $(php -r 'echo PHP_VERSION;')"

echo "==> Checking every Twig template compiles"
php "$PLUGIN_DIR/test/lint-templates.php"

echo "==> Running the test suite"
php "$PLUGIN_DIR/test/run.php"
