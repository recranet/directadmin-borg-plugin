# Test image for the DirectAdmin Borg plugin.
#
# Pinned to PHP 8.1 because that is the native /usr/local/bin/php on the target
# DirectAdmin servers (client sites use alt-php via the PHP selector, but plugin
# shebangs run the native CLI). Testing on a newer PHP would let 8.2+ syntax
# through unnoticed.
#
# Deliberately does NOT contain DirectAdmin: it provides the two things the
# plugin actually talks to — a real borg, and a root-owned /home with 0711 user
# directories — and drives the plugin scripts the way DirectAdmin does, by
# setting USERNAME / QUERY_STRING / POST and reading stdout.
FROM php:8.1-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        borgbackup \
        ca-certificates \
        procps \
        util-linux \
        cron \
        openssh-server \
        openssh-client \
    && rm -rf /var/lib/apt/lists/*

# The official php image already installs PHP at /usr/local/bin/php, which is
# exactly where DirectAdmin plugin shebangs expect to find it.

# Two customer accounts with the permissions DirectAdmin actually gives them,
# so "can the admin user read this?" can be answered for real rather than
# assumed.
RUN useradd -m -d /home/alice -s /bin/false alice \
    && useradd -m -d /home/bob -s /bin/false bob \
    && useradd -m -d /home/admin -s /bin/false admin \
    && chmod 0711 /home/alice /home/bob /home/admin

# Minimal DirectAdmin user registry so Account treats these as DA users.
RUN mkdir -p /usr/local/directadmin/data/users/alice \
             /usr/local/directadmin/data/users/bob \
             /usr/local/directadmin/data/users/admin

# Sample content, including files only the owner can read.
RUN mkdir -p /home/alice/domains/example.com/public_html /home/alice/.secret \
    && echo '<h1>alice site</h1>' > /home/alice/domains/example.com/public_html/index.html \
    && echo 'alice private data' > /home/alice/.secret/notes.txt \
    && echo 'alice db password' > /home/alice/.my.cnf \
    && chmod 0600 /home/alice/.my.cnf /home/alice/.secret/notes.txt \
    && chown -R alice:alice /home/alice \
    && mkdir -p /home/bob/domains/bob.example/public_html \
    && echo '<h1>bob site</h1>' > /home/bob/domains/bob.example/public_html/index.html \
    && echo 'bob private data' > /home/bob/secret.txt \
    && chmod 0600 /home/bob/secret.txt \
    && chown -R bob:bob /home/bob

# DirectAdmin's own per-user backups: the config and database dumps that do not
# live in the home directory. Two compressions on purpose, since which one is
# written depends on the server's backup settings.
RUN mkdir -p /home/admin/admin_backups \
    && echo 'alice config and databases' > /home/admin/admin_backups/alice.tar.zst \
    && echo 'bob config and databases' > /home/admin/admin_backups/bob.tar.gz \
    && echo 'a user that no longer exists' > /home/admin/admin_backups/ghost.tar.gz \
    && chown -R admin:admin /home/admin/admin_backups \
    && chmod 0700 /home/admin/admin_backups

# An SSH server so remote repositories (ssh://user@host/path) can be exercised
# for real rather than only validated as strings. Key-only, localhost, root --
# the same shape as a real borg backup target.
RUN mkdir -p /var/run/sshd /root/.ssh \
    && ssh-keygen -q -t ed25519 -N '' -f /root/.ssh/borg_ed25519 \
    && cat /root/.ssh/borg_ed25519.pub > /root/.ssh/authorized_keys \
    && chmod 700 /root/.ssh && chmod 600 /root/.ssh/authorized_keys \
    && printf 'PermitRootLogin prohibit-password\nPasswordAuthentication no\n' >> /etc/ssh/sshd_config \
    && ssh-keyscan -H localhost > /root/.ssh/known_hosts 2>/dev/null || true

# A stub reporting borg 1.1, so the --prefix branch of prune (renamed to
# --glob-archives in 1.2) is covered without installing a second borg.
RUN printf '#!/bin/sh\necho "borg 1.1.18"\n' > /usr/local/bin/borg-1.1-stub \
    && chmod 755 /usr/local/bin/borg-1.1-stub

WORKDIR /plugin
ENTRYPOINT ["/bin/bash"]
