# AlmaLinux test image for the DirectAdmin Borg plugin.
#
# This is the family the plugin actually runs on: DirectAdmin servers are
# predominantly EL, and the differences from Debian are the ones that bite —
# extensions are separate RPMs loaded through ini files (so `php -n` sees none
# of them), borg comes from EPEL and trails upstream by a different amount per
# release, and the CLI memory limit is lower.
#
# PHP comes from AppStream. Where the binary came from does not change how it
# behaves; the version and the compiled-in extensions do, and 8.2 is available
# as a module stream on both EL8 and EL9.

ARG EL_VERSION=9
FROM almalinux:${EL_VERSION}

ARG EL_VERSION
ARG PHP_STREAM=8.2
# "epel" takes whatever EPEL ships for this release; "pip" builds a pinned
# upstream version, which is the only way to reach borg 1.4 on EL.
ARG BORG_SOURCE=epel
ARG BORG_VERSION=1.4.5

# EPEL carries borgbackup; CRB/PowerTools carries some of its dependencies.
RUN set -eux; \
    dnf -y install dnf-plugins-core; \
    dnf -y install "https://dl.fedoraproject.org/pub/epel/epel-release-latest-${EL_VERSION}.noarch.rpm"; \
    # The repository holding EPEL's build dependencies was renamed between
    # EL8 (powertools) and EL9 (crb).
    if [ "$EL_VERSION" = "8" ]; then \
        dnf -y config-manager --set-enabled powertools || dnf -y config-manager --set-enabled PowerTools; \
    else \
        dnf -y config-manager --set-enabled crb; \
    fi; \
    dnf -y update

# DirectAdmin plugin scripts run whatever /usr/local/bin/php points at, which on
# a real server is the CustomBuild CLI build.
RUN set -eux; \
    dnf -y module reset php; \
    dnf -y module enable "php:${PHP_STREAM}"; \
    dnf -y install php-cli php-mbstring; \
    dnf clean all; \
    ln -sf "$(command -v php)" /usr/local/bin/php; \
    php -v; \
    test "$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')" = "${PHP_STREAM}"

RUN set -eux; \
    dnf -y install openssh-server openssh-clients util-linux procps-ng cronie shadow-utils; \
    if [ "$BORG_SOURCE" = "epel" ]; then \
        dnf -y install borgbackup; \
    else \
        # borg 1.4 needs Python 3.10 or newer; EL9's default python3 is 3.9,
        # so build against the parallel-installable 3.11 from AppStream.
        dnf -y install python3.11 python3.11-devel python3.11-pip gcc make pkgconfig \
            openssl-devel libacl-devel lz4-devel libzstd-devel xxhash-devel; \
        python3.11 -m venv /opt/borg; \
        /opt/borg/bin/pip install --no-cache-dir --upgrade pip setuptools wheel; \
        /opt/borg/bin/pip install --no-cache-dir "borgbackup==${BORG_VERSION}"; \
        ln -sf /opt/borg/bin/borg /usr/local/bin/borg; \
    fi; \
    dnf clean all; \
    borg --version

COPY setup-fixtures.sh /tmp/setup-fixtures.sh
RUN sh /tmp/setup-fixtures.sh && rm -f /tmp/setup-fixtures.sh

WORKDIR /plugin
ENTRYPOINT ["/bin/bash"]
