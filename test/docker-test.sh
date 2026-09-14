#!/bin/sh
# Build the test image and run the suite. Safe to run on a workstation: it
# never contacts a DirectAdmin server.
set -eu

cd "$(dirname "$0")/docker"

if command -v docker >/dev/null 2>&1; then
    exec docker compose run --rm --build plugin-test
fi

echo "docker is not installed or not on PATH." >&2
exit 1
