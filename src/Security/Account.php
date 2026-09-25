<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Security;

use Recranet\DirectAdminBorg\Exception\BorgPluginException;
use Recranet\DirectAdminBorg\Paths;

/**
 * A DirectAdmin/UNIX account and the boundary of what it may restore.
 *
 * Plugin code runs as root even at user level, so this class is the only thing
 * between a crafted request and another customer's data. The home directory is
 * read from /etc/passwd and never taken from the request.
 */
final class Account
{
    private function __construct(
        public readonly string $username,
        public readonly string $home,
        public readonly int $uid,
        public readonly int $gid,
    ) {
    }

    /**
     * @throws BorgPluginException when the name is not a usable local account
     */
    /**
     * Whether a string could be a Unix account name at all.
     *
     * Rejected rather than sanitised, deliberately. The name is an identity
     * that has to match a real account and a real /home/<user>, so there is no
     * "close enough": stripping the bad characters out of "../../etc" yields
     * "etc", which is a different name that the operator never typed and which
     * may well exist. A restore tool acting on a name nobody asked for is worse
     * than one that says the name is wrong. Sanitising paths by substitution is
     * also the classic way to get this wrong -- "....//" survives one pass of
     * removing "../" and comes out as "../".
     *
     * The caller trims whitespace; nothing else is forgiven.
     */
    public static function isValidName(string $username): bool
    {
        return (bool) preg_match('/^[a-z_][a-z0-9_-]{0,31}$/i', $username);
    }

    public static function resolve(string $username, Paths $paths): self
    {
        if (!self::isValidName($username)) {
            throw new BorgPluginException('Invalid account name.');
        }

        $entry = self::lookupPasswd($username, $paths->passwdFile);
        if ($entry === null) {
            throw new BorgPluginException(\sprintf('No local account named "%s".', $username));
        }

        [$uid, $gid, $home] = $entry;

        if ($uid === 0) {
            throw new BorgPluginException('Refusing to treat a uid 0 account as a restore target.');
        }

        $home = PathGuard::normalize($home);
        // A home of "/" or one that does not exist would make confine() accept
        // the entire filesystem.
        if ($home === '/') {
            throw new BorgPluginException('Account home directory is the filesystem root.');
        }
        if (!is_dir($home)) {
            throw new BorgPluginException('Account has no usable home directory.');
        }

        // When DirectAdmin's user registry is present, require a real DA user
        // rather than any system account that happens to exist.
        if (is_dir($paths->daUsersDir) && !is_dir($paths->daUsersDir . '/' . $username)) {
            throw new BorgPluginException(\sprintf('"%s" is not a DirectAdmin user.', $username));
        }

        return new self($username, $home, $uid, $gid);
    }

    /** @return array{0:int,1:int,2:string}|null */
    private static function lookupPasswd(string $username, string $passwdFile): ?array
    {
        if (!is_readable($passwdFile)) {
            return null;
        }
        $handle = @fopen($passwdFile, 'r');
        if ($handle === false) {
            return null;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $fields = explode(':', rtrim($line, "\r\n"));
                if (\count($fields) < 6 || $fields[0] !== $username) {
                    continue;
                }

                return [(int) $fields[2], (int) $fields[3], $fields[5]];
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    /**
     * Normalise a path from the request and assert it is inside this home.
     *
     * @throws \Recranet\DirectAdminBorg\Exception\UnsafePathException
     */
    public function confine(string $path): string
    {
        return PathGuard::confine($path, $this->home);
    }
}
