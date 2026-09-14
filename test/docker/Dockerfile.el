# AlmaLinux test image for the DirectAdmin Borg plugin.
#
# This is the family the plugin actually runs on: DirectAdmin servers are
# predominantly EL, and the differences from Debian are real — PHP arrives as an
# AppStream module rather than a single package, extensions are separate RPMs
# loaded through ini files (so `php -n` sees none of them), and borg comes from
# EPEL, whose version trails upstream by a different amount per release.
# Declared before FROM so it can select the base image, then re-declared after
# it, since an ARG from the global scope is not visible inside a build stage.
ARG EL_VERSION=9
FROM almalinux:${EL_VERSION}

ARG EL_VERSION
ARG PHP_STREAM=8.1

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
# a DirectAdmin server is the CustomBuild CLI build. PHP 8.1 is taken from Remi
# rather than AppStream because EL8 has no 8.1 stream at all (7.2 through 8.0,
# then 8.2), and pinning the version matters more here than the packaging route:
# the point of this image is to catch 8.2+ syntax before it reaches a server.
RUN set -eux; \
    dnf -y install "https://rpms.remirepo.net/enterprise/remi-release-${EL_VERSION}.rpm"; \
    dnf -y module reset php; \
    dnf -y module enable "php:remi-${PHP_STREAM}"; \
    dnf -y install php-cli php-mbstring; \
    ln -sf "$(command -v php)" /usr/local/bin/php; \
    php -v; \
    php -r 'exit(PHP_VERSION_ID >= 80100 && PHP_VERSION_ID < 80200 ? 0 : 1);'

RUN set -eux; \
    dnf -y install borgbackup openssh-server openssh-clients util-linux procps-ng cronie shadow-utils tar; \
    dnf clean all; \
    borg --version

COPY setup-fixtures.sh /tmp/setup-fixtures.sh
RUN sh /tmp/setup-fixtures.sh && rm -f /tmp/setup-fixtures.sh

WORKDIR /plugin
ENTRYPOINT ["/bin/bash"]
