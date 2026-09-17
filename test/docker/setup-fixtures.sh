#!/bin/sh
# Test fixtures shared by every distribution image.
#
# Builds the two things the plugin actually talks to: a root-owned /home with
# DirectAdmin's 0711 user directories, and a DirectAdmin user registry. Plus an
# SSH target for remote-repository tests and a stub borg for version-branch
# tests.
set -eux

# Customer accounts with the permissions DirectAdmin actually gives them, so
# "can the admin user read this?" is answered rather than assumed.
useradd -m -d /home/alice -s /bin/false alice
useradd -m -d /home/bob   -s /bin/false bob
useradd -m -d /home/admin -s /bin/false admin
chmod 0711 /home/alice /home/bob /home/admin

# Minimal DirectAdmin user registry, so Account treats these as DA users.
mkdir -p /usr/local/directadmin/data/users/alice \
         /usr/local/directadmin/data/users/bob \
         /usr/local/directadmin/data/users/admin

# Site content, including files only the owner can read.
mkdir -p /home/alice/domains/example.com/public_html /home/alice/.secret
echo '<h1>alice site</h1>' > /home/alice/domains/example.com/public_html/index.html
echo 'alice private data'  > /home/alice/.secret/notes.txt
echo 'alice db password'   > /home/alice/.my.cnf
chmod 0600 /home/alice/.my.cnf /home/alice/.secret/notes.txt
chown -R alice:alice /home/alice

mkdir -p /home/bob/domains/bob.example/public_html
echo '<h1>bob site</h1>' > /home/bob/domains/bob.example/public_html/index.html
echo 'bob private data'  > /home/bob/secret.txt
chmod 0600 /home/bob/secret.txt
chown -R bob:bob /home/bob

# DirectAdmin's own per-user backups: config and database dumps, which do not
# live in the home directory. Two compressions on purpose, since which one is
# written depends on the server's backup settings.
mkdir -p /home/admin/admin_backups
# DirectAdmin writes these as <level>.<creator>.<user>.tar.<ext>. The plain
# <user>.tar.<ext> form exists in some setups, so both shapes are here -- and
# "beaujean" is here to catch a suffix match that forgets the leading dot and
# hands back the wrong customer's databases for user "jean".
echo 'alice config and databases'   > /home/admin/admin_backups/user.admin.alice.tar.zst
echo 'bob config and databases'     > /home/admin/admin_backups/bob.tar.gz
echo 'a user that no longer exists' > /home/admin/admin_backups/ghost.tar.gz
echo 'beaujean config'              > /home/admin/admin_backups/user.admin.beaujean.tar.zst
echo 'jean config'                  > /home/admin/admin_backups/user.admin.jean.tar.zst
chown -R admin:admin /home/admin/admin_backups
chmod 0700 /home/admin/admin_backups

# An SSH target so remote repositories (ssh://user@host/path) are exercised for
# real rather than only validated as strings. Key-only, localhost, root: the
# same shape as a real borg backup target.
mkdir -p /var/run/sshd /root/.ssh
ssh-keygen -A
ssh-keygen -q -t ed25519 -N '' -f /root/.ssh/borg_ed25519
cat /root/.ssh/borg_ed25519.pub > /root/.ssh/authorized_keys
chmod 700 /root/.ssh
chmod 600 /root/.ssh/authorized_keys
printf 'PermitRootLogin prohibit-password\nPasswordAuthentication no\n' >> /etc/ssh/sshd_config

# A stub reporting borg 1.1, so the --prefix branch of prune (renamed to
# --glob-archives in 1.2) is covered even on images carrying a newer borg.
printf '#!/bin/sh\necho "borg 1.1.18"\n' > /usr/local/bin/borg-1.1-stub
chmod 755 /usr/local/bin/borg-1.1-stub
