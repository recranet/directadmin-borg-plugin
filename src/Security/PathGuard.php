<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Security;

use Recranet\DirectAdminBorg\Exception\UnsafePathException;

/**
 * Lexical path handling.
 *
 * Deliberately does not touch the filesystem: the same rules have to apply to
 * paths that only exist inside a borg archive, where realpath() would resolve
 * nothing. Symlink resolution is therefore not available here, which is why
 * restores are written into a dedicated directory rather than over live files.
 */
final class PathGuard
{
    /**
     * Normalise an absolute path, resolving "." and ".." textually.
     *
     * @throws UnsafePathException on relative paths, NUL bytes, or traversal
     *                             past the filesystem root
     */
    public static function normalize(string $path): string
    {
        // A NUL byte is never legitimate in a path; treat it as an attack
        // rather than silently rewriting the path into something else.
        if (str_contains($path, "\0")) {
            throw new UnsafePathException('Path contains a NUL byte.');
        }
        if ($path === '' || $path[0] !== '/') {
            throw new UnsafePathException('Path must be absolute.');
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    throw new UnsafePathException('Path escapes the filesystem root.');
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    /**
     * True when $path is $root itself or lives beneath it.
     *
     * Both arguments must already be normalised. The trailing separator matters:
     * without it "/home/bobby" would count as inside "/home/bob".
     */
    public static function isWithin(string $path, string $root): bool
    {
        if ($root === '/') {
            return true;
        }

        $root = rtrim($root, '/');

        return $path === $root || str_starts_with($path, $root . '/');
    }

    /**
     * Normalise $path and assert it is inside $root.
     *
     * @throws UnsafePathException when it is not
     */
    public static function confine(string $path, string $root): string
    {
        $normalized = self::normalize($path);
        if (!self::isWithin($normalized, $root)) {
            throw new UnsafePathException('Path is outside the permitted directory.');
        }

        return $normalized;
    }

    /** Archive members are stored without a leading slash. */
    public static function toArchiveMember(string $absolutePath): string
    {
        return ltrim(self::normalize($absolutePath), '/');
    }
}
