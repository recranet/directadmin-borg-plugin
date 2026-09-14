<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Borg;

/** One file or directory as listed inside an archive. */
final class ArchiveEntry
{
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly string $type,
        public readonly int $size,
        public readonly string $mode,
        public readonly string $owner,
        public readonly string $modified,
    ) {
    }

    /** @param array<string,mixed> $row a decoded `borg list --json-lines` row */
    public static function fromJsonLine(array $row, ?string $name = null): self
    {
        $path = '/' . ltrim((string) ($row['path'] ?? ''), '/');

        return new self(
            $path,
            $name ?? basename($path),
            (string) ($row['type'] ?? '-'),
            (int) ($row['size'] ?? 0),
            (string) ($row['mode'] ?? ''),
            trim(((string) ($row['user'] ?? '')) . ':' . ((string) ($row['group'] ?? '')), ':'),
            (string) ($row['mtime'] ?? ''),
        );
    }

    public function isDirectory(): bool
    {
        return $this->type === 'd';
    }
}
