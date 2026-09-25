<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Job;

use Recranet\DirectAdminBorg\Config\Configuration;
use Recranet\DirectAdminBorg\Exception\BorgPluginException;

/**
 * The hours the server's own backup runs in, when the plugin starts nothing.
 *
 * Every job the plugin runs reads the repository, and a read holds borg's
 * shared lock for as long as it runs. borg create needs the lock exclusively
 * and by default waits one second for it, so a backup that starts while a
 * restore or an index is reading does not wait its turn: it fails, for every
 * account on the server, and says so only in the backup's own log. A customer
 * who keeps asking for an index can arrange that on purpose.
 *
 * The fix on the backup's side is --lock-wait, but the backup script belongs
 * to the server rather than to this plugin and is not something to change on
 * every host. Nobody restores in the middle of the night either. So the plugin
 * keeps out of the way instead: in the window no job starts, at either level,
 * and the worker checks again before it touches the repository, so a job
 * queued a moment before the window does not slip in after it opened.
 *
 * What it cannot do is stop a job already running when the window opens --
 * killing a restore halfway would be worse than the collision. That is why
 * the window should open well before the backup does.
 *
 * Wall-clock time in the server's own timezone, because that is what cron
 * reads. Scripts run with php -n, where PHP's timezone is UTC whatever the
 * server is set to, so it is taken from /etc/localtime instead.
 */
final class BackupWindow
{
    private function __construct(
        private readonly ?int $from,
        private readonly ?int $until,
        private readonly \DateTimeZone $timezone,
    ) {
    }

    public static function fromConfiguration(Configuration $config, ?\DateTimeZone $timezone = null): self
    {
        $from = self::minutes($config->jobsPausedFrom());
        $until = self::minutes($config->jobsPausedUntil());

        // Half a window, or one that starts where it ends, is no window.
        if ($from === null || $until === null || $from === $until) {
            $from = $until = null;
        }

        return new self($from, $until, $timezone ?? self::systemTimezone());
    }

    public function isEnabled(): bool
    {
        return $this->from !== null;
    }

    public function isActive(?\DateTimeImmutable $now = null): bool
    {
        if ($this->from === null || $this->until === null) {
            return false;
        }

        $now = ($now ?? new \DateTimeImmutable())->setTimezone($this->timezone);
        $minute = (int) $now->format('G') * 60 + (int) $now->format('i');

        // 22:00-07:00 wraps past midnight; 01:00-06:00 does not.
        return $this->from < $this->until
            ? $minute >= $this->from && $minute < $this->until
            : $minute >= $this->from || $minute < $this->until;
    }

    /**
     * Refuse to start a job inside the window.
     *
     * @throws BorgPluginException
     */
    public function assertOpen(?\DateTimeImmutable $now = null): void
    {
        if ($this->isActive($now)) {
            throw new BorgPluginException($this->message());
        }
    }

    /** What a page shows, and what a refused job records. */
    public function message(): string
    {
        return \sprintf(
            'Restores and checks are paused between %s and %s while the server backs up. Try again after %s.',
            $this->format($this->from),
            $this->format($this->until),
            $this->format($this->until)
        );
    }

    /** "HH:MM" to minutes past midnight; null for anything else, including empty. */
    public static function minutes(string $time): ?int
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches)) {
            return null;
        }

        return (int) $matches[1] * 60 + (int) $matches[2];
    }

    /**
     * The zone cron runs in. /etc/localtime is a link into the zoneinfo tree on
     * every EL release; a server where it is not falls back to PHP's own
     * setting, which under php -n is UTC.
     */
    public static function systemTimezone(): \DateTimeZone
    {
        $link = @readlink('/etc/localtime');
        if (\is_string($link) && preg_match('#zoneinfo/(.+)$#', $link, $matches)) {
            try {
                return new \DateTimeZone($matches[1]);
            } catch (\Exception) {
                // Not a zone PHP knows; fall through.
            }
        }

        return new \DateTimeZone(date_default_timezone_get());
    }

    private function format(?int $minutes): string
    {
        return $minutes === null ? '' : \sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
