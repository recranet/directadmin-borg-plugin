<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Security;

use Recranet\DirectAdminBorg\Paths;
use Symfony\Component\Filesystem\Filesystem;

/**
 * CSRF tokens for plugin forms.
 *
 * DirectAdmin has already authenticated the request by the time a plugin script
 * runs, so the risk this closes is a third-party page POSTing to the plugin on
 * behalf of a logged-in admin. The token is an HMAC over the account and access
 * level, rotating every 12 hours with the previous window still accepted so a
 * form left open overnight does not simply fail.
 *
 * Not symfony/security-csrf: that stores tokens in a session, and a plugin
 * script has no session of its own — DirectAdmin owns the session and exposes
 * no handle to it.
 */
final class CsrfTokenizer
{
    private const WINDOW_SECONDS = 43200;

    public function __construct(
        private readonly Paths $paths,
        private readonly Filesystem $filesystem
    ) {
    }

    public function token(string $level, string $username): string
    {
        return $this->forWindow($level, $username, $this->currentWindow());
    }

    public function isValid(string $level, string $username, ?string $supplied): bool
    {
        if ($supplied === null || $supplied === '') {
            return false;
        }

        $window = $this->currentWindow();

        foreach ([$window, $window - 1] as $candidate) {
            if (hash_equals($this->forWindow($level, $username, $candidate), $supplied)) {
                return true;
            }
        }

        return false;
    }

    private function currentWindow(): int
    {
        return intdiv(time(), self::WINDOW_SECONDS);
    }

    private function forWindow(string $level, string $username, int $window): string
    {
        return hash_hmac('sha256', $level . '|' . $username . '|' . $window, $this->secret());
    }

    private function secret(): string
    {
        $file = $this->paths->secretFile();

        if (is_file($file)) {
            $secret = trim((string) @file_get_contents($file));
            if (\strlen($secret) >= 32) {
                return $secret;
            }
        }

        $secret = bin2hex(random_bytes(32));
        $this->filesystem->dumpFile($file, $secret . "\n");
        $this->filesystem->chmod($file, 0600);

        return $secret;
    }
}
