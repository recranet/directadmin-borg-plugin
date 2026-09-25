#!/bin/sh
# Install or update the plugin on DirectAdmin servers over ssh.
#
#   make package                        # build dist/borg.tar.gz first
#   sh scripts/deploy.sh host [host...]
#
# Hosts are arguments rather than a list kept in the repository: which servers
# run this plugin is deployment detail, and this repository is public.
#
# An upgrade is a swap, not an extract over the top. The running plugin is
# renamed aside and a freshly extracted tree takes its place, so a file deleted
# since the installed version does not survive the upgrade -- extracting over
# the old tree would leave it behind. The rollback copy goes to /root and never
# beside the plugin, because DirectAdmin treats every directory under plugins/
# holding a plugin.conf as an installed plugin, so a copy there shows up as a
# second "Borg Backup" in the Plugin Manager.
#
# Nothing here touches /var/lib/directadmin-borg. The repository location,
# passphrase and job history live outside the plugin directory precisely so an
# upgrade cannot take them with it, and this verifies config.json is
# byte-identical afterwards rather than assuming it.
set -eu

REPO_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
TARBALL="${BORG_TARBALL:-$REPO_DIR/dist/borg.tar.gz}"
PLUG=/usr/local/directadmin/plugins

log()  { printf '%s\n' "$*"; }
warn() { printf 'WARN: %s\n' "$*" >&2; }
fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

[ $# -gt 0 ] || fail "usage: sh scripts/deploy.sh host [host...]"
[ -f "$TARBALL" ] || fail "$TARBALL not found. Run 'make package' first."

VERSION=$(tar -xzOf "$TARBALL" plugin.conf | sed -n 's/^version=//p')
[ -n "$VERSION" ] || fail "$TARBALL has no version in plugin.conf."

# The floor is read from the guard that enforces it rather than repeated here,
# so that raising one cannot leave the other behind.
FLOOR=$(sed -n 's/.*PHP_VERSION_ID < \([0-9]*\).*/\1/p' "$REPO_DIR/bootstrap.php" | head -1)
[ -n "$FLOOR" ] || fail "Could not read the PHP floor from bootstrap.php."

SUM=$(sha256sum "$TARBALL" 2>/dev/null || shasum -a 256 "$TARBALL")
SUM=${SUM%% *}

log "Deploying $VERSION (needs PHP_VERSION_ID >= $FLOOR)"
log "  $TARBALL"
log "  sha256 $SUM"

deployed=""
failed=""

for host in "$@"; do
    log ""
    log "--- $host ------------------------------------------------------"

    # -------------------------------------------------------------- preflight
    # Everything that can refuse a host runs BEFORE the working plugin is moved
    # aside. install.sh rejects a PHP below the floor too, but it only runs
    # after the swap, which would leave the server holding a version it cannot
    # start.
    pre=$(ssh -o ConnectTimeout=10 -o BatchMode=yes "root@$host" "set -u
        if [ ! -x /usr/local/bin/php ]; then echo 'FAIL no /usr/local/bin/php'; exit 0; fi
        id=\$(/usr/local/bin/php -n -r 'echo PHP_VERSION_ID;' 2>/dev/null || echo 0)
        ver=\$(/usr/local/bin/php -n -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)
        if [ \"\$id\" -lt $FLOOR ]; then echo \"FAIL PHP \$ver is below the floor\"; exit 0; fi
        if ! command -v borg >/dev/null 2>&1; then echo 'FAIL borg is not installed'; exit 0; fi
        if ps -eo args | grep -q '[b]in/console borg:job'; then echo 'FAIL a plugin job is running'; exit 0; fi

        cur=none
        if [ -f $PLUG/borg/plugin.conf ]; then cur=\$(sed -n 's/^version=//p' $PLUG/borg/plugin.conf); fi
        cfg=absent
        if [ -f /var/lib/directadmin-borg/config.json ]; then
            cfg=\$(sha256sum /var/lib/directadmin-borg/config.json | cut -d' ' -f1)
        fi
        # A rename is atomic; a cross-device move is a copy. Still correct, but
        # worth saying out loud before it is attempted on a live plugin.
        same=yes
        if [ \"\$(stat -c %d $PLUG 2>/dev/null)\" != \"\$(stat -c %d /root 2>/dev/null)\" ]; then same=no; fi
        echo \"OK \$ver \$cur \$cfg \$same \$(borg --version 2>/dev/null | awk '{print \$2}')\"
    " 2>/dev/null | grep -E '^(OK|FAIL)' || true)

    if [ -z "$pre" ]; then
        warn "$host: unreachable or preflight produced nothing; skipped"
        failed="$failed $host"
        continue
    fi
    case "$pre" in
        FAIL*)
            warn "$host: ${pre#FAIL }; skipped"
            failed="$failed $host"
            continue
            ;;
    esac

    # read, not set --, so the host list in "$@" is left alone.
    IFS=' ' read -r _ php_ver cur_ver cfg_before same_fs borg_ver <<PREFLIGHT
$pre
PREFLIGHT

    log "  PHP $php_ver, borg $borg_ver, installed: $cur_ver -> $VERSION"
    if [ "$same_fs" != yes ]; then
        warn "$host: /root is on another filesystem, so the swap is a copy, not a rename"
    fi

    # ----------------------------------------------------------------- upload
    if ! scp -q "$TARBALL" "root@$host:/root/borg-$VERSION.tar.gz"; then
        warn "$host: upload failed; skipped"
        failed="$failed $host"
        continue
    fi

    # -------------------------------------------------------- swap and install
    if ! ssh "root@$host" "set -eu
        got=\$(sha256sum /root/borg-$VERSION.tar.gz | cut -d' ' -f1)
        if [ \"\$got\" != '$SUM' ]; then echo 'checksum mismatch after upload' >&2; exit 1; fi

        rm -rf $PLUG/borg.new
        mkdir -p $PLUG/borg.new
        tar -xzf /root/borg-$VERSION.tar.gz -C $PLUG/borg.new

        # Prove the new tree is whole before the working one is disturbed.
        if ! grep -q '^version=$VERSION\$' $PLUG/borg.new/plugin.conf; then
            rm -rf $PLUG/borg.new; echo 'extracted tree has the wrong version' >&2; exit 1
        fi
        if [ ! -f $PLUG/borg.new/vendor/autoload.php ]; then
            rm -rf $PLUG/borg.new; echo 'extracted tree has no vendor/' >&2; exit 1
        fi

        if [ -d $PLUG/borg ]; then
            rm -rf /root/borg.bak-$cur_ver
            mv $PLUG/borg /root/borg.bak-$cur_ver
        fi
        mv $PLUG/borg.new $PLUG/borg

        if ! sh $PLUG/borg/scripts/install.sh >/tmp/borg-install.log 2>&1; then
            cat /tmp/borg-install.log >&2; exit 1
        fi
    "; then
        warn "$host: install failed."
        if [ "$cur_ver" != none ]; then
            warn "  roll back: ssh root@$host 'rm -rf $PLUG/borg && mv /root/borg.bak-$cur_ver $PLUG/borg'"
        fi
        failed="$failed $host"
        continue
    fi

    # ----------------------------------------------------------------- verify
    # borg:status runs through the console and would miss a template or
    # request-decoding failure, which is the sort of thing a dependency bump
    # introduces. So the DirectAdmin entry points are rendered as well, the way
    # DirectAdmin invokes them: environment in, stdout out.
    if ssh "root@$host" "set -u
        ok=0; bad=0
        check() {
            if [ \"\$1\" = 0 ]; then ok=\$((ok+1)); else bad=\$((bad+1)); echo \"    FAIL: \$2\"; fi
        }

        v=\$(sed -n 's/^version=//p' $PLUG/borg/plugin.conf)
        [ \"\$v\" = '$VERSION' ]; check \$? \"plugin.conf says '\$v', not $VERSION\"

        cfg=absent
        if [ -f /var/lib/directadmin-borg/config.json ]; then
            cfg=\$(sha256sum /var/lib/directadmin-borg/config.json | cut -d' ' -f1)
        fi
        [ \"\$cfg\" = '$cfg_before' ]; check \$? 'config.json changed during the upgrade'

        [ ! -e $PLUG/borg.new ]; check \$? 'a borg.new was left behind in plugins/'
        [ -z \"\$(ls -d $PLUG/borg.bak* 2>/dev/null || true)\" ]; check \$? 'a rollback copy was left in plugins/'

        $PLUG/borg/bin/console borg:status >/tmp/borg-status.log 2>&1
        check \$? 'borg:status did not exit cleanly'
        archives=\$(sed -n 's/^Backups (\([0-9]*\)).*/\1/p' /tmp/borg-status.log | head -1)

        u2=\$(ls /usr/local/directadmin/data/users/ 2>/dev/null | grep -v '^admin\$' | head -1)
        for page in admin user; do
            who=admin
            if [ \$page = user ]; then who=\$u2; fi
            if [ -z \"\$who\" ]; then continue; fi
            out=\$(USERNAME=\$who REQUEST_METHOD=GET QUERY_STRING='' $PLUG/borg/\$page/index.html 2>&1)
            rc=\$?
            [ \$rc = 0 ] && [ \${#out} -gt 500 ]
            check \$? \"\$page page did not render (exit \$rc, \${#out} bytes)\"
            if printf '%s' \"\$out\" | grep -qiE 'fatal error|uncaught exception'; then
                check 1 \"\$page page rendered a PHP error\"
            else
                check 0 ''
            fi
        done
        rm -f /tmp/borg-status.log /tmp/borg-install.log

        if [ \$bad = 0 ]; then
            echo \"  OK: \$ok checks passed, \${archives:-0} backups visible\"
            exit 0
        fi
        echo \"  \$bad of \$((ok+bad)) checks FAILED\"
        exit 1
    "; then
        if [ "$cur_ver" = none ]; then
            log "  fresh install; no rollback copy"
        else
            log "  rollback at /root/borg.bak-$cur_ver"
        fi
        deployed="$deployed $host"
    else
        warn "$host: deployed but verification failed -- look before rolling back"
        failed="$failed $host"
    fi
done

log ""
log "On $VERSION:${deployed:- none}"
if [ -n "$failed" ]; then
    fail "Not deployed:$failed"
fi
