#!/bin/sh
# Build the test images and run the suite. Safe to run on a workstation: it
# never contacts a DirectAdmin server.
#
#   test/docker-test.sh              # the environments that mirror production
#   test/docker-test.sh alma9        # one of them
#   test/docker-test.sh alma9-borg14 # opt-in: borg newer than EPEL ships
#
# The default set installs borg the way a DirectAdmin server does, from EPEL.
set -eu

cd "$(dirname "$0")/docker"

command -v docker >/dev/null 2>&1 || { echo "docker is not installed or not on PATH." >&2; exit 1; }

SERVICES=${*:-"alma9 alma8"}
FAILED=""

for service in $SERVICES; do
    echo ""
    echo "################################################################"
    echo "# $service"
    echo "################################################################"

    if docker compose run --rm --build "$service"; then
        echo "# $service: PASS"
    else
        echo "# $service: FAIL"
        FAILED="$FAILED $service"
    fi
done

echo ""
if [ -n "$FAILED" ]; then
    echo "FAILED:$FAILED"
    exit 1
fi

echo "All environments passed."
