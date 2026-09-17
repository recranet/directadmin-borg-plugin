<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Support;

/** Presentation helpers, exposed to templates as Twig filters. */
final class Format
{
    public static function bytes(mixed $bytes): string
    {
        if ($bytes === null || $bytes === '' || !is_numeric($bytes)) {
            return '-';
        }

        $value = (float) $bytes;
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $index = 0;

        while ($value >= 1024 && $index < \count($units) - 1) {
            $value /= 1024;
            ++$index;
        }

        return \sprintf($index === 0 ? '%d %s' : '%.1f %s', $value, $units[$index]);
    }

    /**
     * borg writes archive timestamps as ISO-8601 without a timezone; treat
     * those as server-local rather than silently assuming UTC.
     */
    public static function dateTime(mixed $isoTime): string
    {
        $isoTime = (string) $isoTime;
        if (trim($isoTime) === '') {
            return '-';
        }

        try {
            return (new \DateTimeImmutable($isoTime))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $isoTime;
        }
    }

    /**
     * The date alone, spelled out.
     *
     * Archives are picked by "which day do I want back", so the list reads as
     * dates rather than as timestamps. Written out in full because 09-08 is
     * ambiguous between two continents and a restore is the wrong place to
     * guess.
     */
    public static function date(mixed $isoTime): string
    {
        $isoTime = (string) $isoTime;
        if (trim($isoTime) === '') {
            return '-';
        }

        try {
            return (new \DateTimeImmutable($isoTime))->format('j F Y');
        } catch (\Throwable) {
            return $isoTime;
        }
    }

    /** The time of day alone, to sit beside date(). */
    public static function time(mixed $isoTime): string
    {
        $isoTime = (string) $isoTime;
        if (trim($isoTime) === '') {
            return '-';
        }

        try {
            return (new \DateTimeImmutable($isoTime))->format('H:i');
        } catch (\Throwable) {
            return '';
        }
    }

    public static function age(mixed $isoTime): string
    {
        $isoTime = (string) $isoTime;
        if (trim($isoTime) === '') {
            return 'never';
        }

        try {
            $then = new \DateTimeImmutable($isoTime);
        } catch (\Throwable) {
            return '-';
        }

        $seconds = time() - $then->getTimestamp();
        if ($seconds < 0) {
            return 'in the future';
        }

        foreach ([[86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$size, $label]) {
            if ($seconds >= $size) {
                $count = intdiv($seconds, $size);

                return \sprintf('%d %s%s ago', $count, $label, $count === 1 ? '' : 's');
            }
        }

        return 'just now';
    }

    /**
     * The uid this process runs as. posix_getuid() is not guaranteed to be
     * available when PHP is invoked with -n, so fall back to procfs before
     * assuming root.
     */
    public static function currentUid(): int
    {
        if (\function_exists('posix_getuid')) {
            return posix_getuid();
        }

        $stat = @stat('/proc/self');

        return \is_array($stat) ? (int) $stat['uid'] : 0;
    }
}
