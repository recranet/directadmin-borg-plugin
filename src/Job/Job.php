<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Job;

/** A single background operation and its recorded state. */
final class Job
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_WARNING = 'warning';
    public const STATUS_FAILED = 'failed';

    public const TYPE_CHECK = 'check';
    public const TYPE_RESTORE = 'restore';

    public const TYPES = [self::TYPE_CHECK, self::TYPE_RESTORE];

    /**
     * Types that take the repository lock and must not overlap.
     *
     * A check only reads, but it reads the whole repository and holds borg's
     * own lock while it does, so letting two run at once just makes both slow.
     * Restores are excluded on purpose: they must stay possible while a check,
     * or the server's own backup cron, is running.
     */
    public const EXCLUSIVE_TYPES = [self::TYPE_CHECK];

    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly string $id,
        private array $data,
    ) {
    }

    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^\d{8}-\d{6}-\d{6}-[a-z]+-[0-9a-f]{8}$/', $id);
    }

    /**
     * A sortable id: date, time, microseconds, type, random suffix.
     *
     * The microseconds matter. Job listings are ordered by filename, which is
     * far cheaper than reading every record to sort on a field — but with only
     * second resolution, two jobs started in the same second would order by
     * their random suffix, i.e. arbitrarily.
     */
    public static function generateId(string $type): string
    {
        $now = microtime(true);
        $seconds = (int) $now;
        $microseconds = min(999999, (int) round(($now - $seconds) * 1000000));

        return \sprintf(
            '%s-%06d-%s-%s',
            date('Ymd-His', $seconds),
            $microseconds,
            $type,
            bin2hex(random_bytes(4))
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /** @param array<string,mixed> $changes */
    public function set(array $changes): void
    {
        $this->data = array_merge($this->data, $changes);
    }

    public function type(): string
    {
        return (string) ($this->data['type'] ?? '');
    }

    public function owner(): string
    {
        return (string) ($this->data['owner'] ?? '');
    }

    public function status(): string
    {
        return (string) ($this->data['status'] ?? self::STATUS_QUEUED);
    }

    public function message(): string
    {
        return (string) ($this->data['message'] ?? '');
    }

    /** @return array<string,mixed> */
    public function params(): array
    {
        $params = $this->data['params'] ?? [];

        return \is_array($params) ? $params : [];
    }

    public function isFinished(): bool
    {
        return \in_array($this->status(), [self::STATUS_SUCCESS, self::STATUS_WARNING, self::STATUS_FAILED], true);
    }

    public function isRunning(): bool
    {
        return !$this->isFinished();
    }

    /** CSS modifier used by the UI to colour the status badge. */
    public function badge(): string
    {
        return match ($this->status()) {
            self::STATUS_SUCCESS => 'ok',
            self::STATUS_WARNING => 'warn',
            self::STATUS_FAILED  => 'err',
            self::STATUS_RUNNING => 'busy',
            default              => 'idle',
        };
    }
}
