<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Borg;

/**
 * Reads the date out of an archive name.
 *
 * Archive names are whatever the backup script's template produced, and the
 * template is nearly always a prefix followed by a timestamp:
 * `{fqdn}-{now:%Y-%m-%d_%H:%M}` gives `srv01.example.com-2026-09-17_01:01`.
 * Showing that whole string in a list is showing the hostname 200 times and the
 * one part that differs in small print at the end, so the date is pulled out
 * and shown on its own.
 *
 * The recorded creation time is not used for the label, because the name is
 * what the operator's own script chose to call the run and what they would
 * type back to borg. It is used for the age beside it, since that one is
 * borg's own and cannot be changed by renaming.
 *
 * Detection is deliberately conservative: an unrecognised name yields null and
 * the caller falls back to the recorded time, rather than guessing at digits
 * that might be a version or a ticket number.
 */
final class ArchiveName
{
    /**
     * Recognised timestamp shapes, most specific first.
     *
     * Anchored at the end, because the timestamp is a suffix in every template
     * borg's own documentation suggests, and a prefix that happens to contain
     * digits (a hostname like `srv01`, a date in a job name) must not win.
     *
     * @var array<string,string> regex => the borg template it corresponds to
     */
    private const PATTERNS = [
        '/(\d{4})-(\d{2})-(\d{2})[_T](\d{2}):(\d{2}):(\d{2})$/' => '{now:%Y-%m-%d_%H:%M:%S}',
        '/(\d{4})-(\d{2})-(\d{2})[_T](\d{2})-(\d{2})-(\d{2})$/' => '{now:%Y-%m-%d_%H-%M-%S}',
        '/(\d{4})-(\d{2})-(\d{2})[_T](\d{2}):(\d{2})$/'         => '{now:%Y-%m-%d_%H:%M}',
        '/(\d{4})-(\d{2})-(\d{2})[_T](\d{2})-(\d{2})$/'         => '{now:%Y-%m-%d_%H-%M}',
        '/(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})$/'        => '{now:%Y%m%d-%H%M%S}',
        '/(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})$/'               => '{now:%Y%m%d-%H%M}',
        '/(\d{4})-(\d{2})-(\d{2})$/'                            => '{now:%Y-%m-%d}',
    ];

    /**
     * The date in an archive name, as an ISO 8601 string, or null.
     *
     * $preferred is the template already detected for this repository; trying
     * it first keeps a repository whose names could match two shapes reading
     * the same way every time.
     */
    public static function date(string $name, ?string $preferred = null): ?string
    {
        $patterns = self::PATTERNS;

        if ($preferred !== null && $preferred !== '') {
            $first = array_search($preferred, $patterns, true);
            if ($first !== false) {
                $patterns = [$first => $preferred] + $patterns;
            }
        }

        foreach ($patterns as $regex => $_) {
            if (!preg_match($regex, $name, $matches)) {
                continue;
            }

            $parts = array_map('intval', \array_slice($matches, 1));
            [$year, $month, $day] = $parts;
            $hour = $parts[3] ?? 0;
            $minute = $parts[4] ?? 0;
            $second = $parts[5] ?? 0;

            if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
                // A name that merely looks like a date -- 2026-13-45 -- is not
                // one, and is better reported as unrecognised than as a
                // silently wrong date on a restore screen.
                continue;
            }

            return \sprintf('%04d-%02d-%02dT%02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
        }

        return null;
    }

    /** The borg template an archive name appears to have come from, or null. */
    public static function format(string $name): ?string
    {
        foreach (self::PATTERNS as $regex => $template) {
            if (preg_match($regex, $name) && self::date($name) !== null) {
                return $template;
            }
        }

        return null;
    }

    /**
     * What is left of the name once the timestamp is taken off.
     *
     * Shown once above the list rather than on every row, so the operator can
     * still see which host or job the archives belong to.
     */
    public static function prefix(string $name): string
    {
        foreach (self::PATTERNS as $regex => $_) {
            if (preg_match($regex, $name)) {
                return rtrim((string) preg_replace($regex, '', $name), '-_.T');
            }
        }

        return $name;
    }
}
