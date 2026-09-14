# Debian-based test image for the DirectAdmin Borg plugin.
#
# Pinned to PHP 8.1 because that is the native /usr/local/bin/php on the target
# DirectAdmin servers (client sites use alt-php via the PHP selector, but plugin
# shebangs run the native CLI). Testing on a newer PHP would let 8.2+ syntax
# through unnoticed.
#
# The borg version is a build argument: Debian stable carries 1.2, while current
# upstream is 1.4, and the two differ in pruning flags and in having a compact
# command.
#
# Deliberately contains no DirectAdmin. It provides what the plugin actually
# talks to, and the suite drives the plugin the way DirectAdmin does: by setting
# USERNAME / QUERY_STRING / POST and reading stdout.
FROM php:8.1-cli-bookworm

ARG BORG_SOURCE=apt
ARG BORG_VERSION=1.4.5

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates procps util-linux cron openssh-server openssh-client \
    && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    if [ "$BORG_SOURCE" = "apt" ]; then \
        apt-get update && apt-get install -y --no-install-recommends borgbackup; \
    else \
        apt-get update && apt-get install -y --no-install-recommends \
            python3-venv python3-dev build-essential pkg-config \
            libacl1-dev libssl-dev liblz4-dev libzstd-dev libxxhash-dev; \
        python3 -m venv /opt/borg; \
        /opt/borg/bin/pip install --no-cache-dir --upgrade pip setuptools wheel; \
        /opt/borg/bin/pip install --no-cache-dir "borgbackup==${BORG_VERSION}"; \
        ln -sf /opt/borg/bin/borg /usr/local/bin/borg; \
        apt-get purge -y build-essential python3-dev && apt-get autoremove -y; \
    fi; \
    rm -rf /var/lib/apt/lists/*; \
    borg --version

COPY setup-fixtures.sh /tmp/setup-fixtures.sh
RUN sh /tmp/setup-fixtures.sh && rm -f /tmp/setup-fixtures.sh

WORKDIR /plugin
ENTRYPOINT ["/bin/bash"]
