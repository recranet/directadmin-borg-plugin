<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Borg;

/** One level of an archive's directory tree. */
final class DirectoryListing
{
    /** @param ArchiveEntry[] $entries */
    public function __construct(
        public readonly array $entries,
        public readonly bool $truncated,
        public readonly bool $readable,
        public readonly string $error = ''
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }
}
