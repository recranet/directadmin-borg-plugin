<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Borg;

/** One archive in the repository. */
final class Archive
{
    public function __construct(
        public readonly string $name,
        public readonly string $time,
    ) {
    }

    /** @param array<string,mixed> $row a `borg list --json` archives entry */
    public static function fromArray(array $row): self
    {
        return new self((string) ($row['name'] ?? ''), (string) ($row['time'] ?? ''));
    }
}
