<?php

/**
 * Shared bootstrap for every entry point.
 *
 * DirectAdmin executes plugin scripts as plain processes, so this file is also
 * the only guard against one being reached through a webserver, where nothing
 * would have authenticated the request.
 */

declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit("This script must be executed by DirectAdmin.\n");
}

if (\PHP_VERSION_ID < 80200) {
    // CustomBuild lets an administrator pin /usr/local/bin/php as far back as
    // 5.6; fail with a clear message rather than a parse error.
    fwrite(\STDERR, 'The Borg plugin requires PHP 8.2 or newer; found ' . \PHP_VERSION . ".\n");
    exit(1);
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(\STDERR, "Dependencies are missing. Run scripts/install.sh, or composer install.\n");
    exit(1);
}

require_once $autoload;

// Everything this plugin writes is root-only by default.
umask(0077);
