<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Job;

use Recranet\DirectAdminBorg\Security\Account;

/**
 * Where a restored file has to end up, when that is not where borg wrote it.
 *
 * Restore Databases is the only caller: DirectAdmin archives its per-user
 * backups under /home/admin/admin_backups, and the User Level restore screen
 * only reads /home/<user>/backups.
 *
 * borg could be pointed straight there -- extract takes --strip-components --
 * but the file is staged and moved instead, because a rename within one
 * filesystem is atomic and an extract is not. DirectAdmin offers whatever is in
 * that directory as something to restore from, so a tarball growing in it for
 * the length of an extract, or a truncated one left behind by a job that died
 * halfway, is a backup somebody can pick.
 *
 * Carried as a resolved object rather than as strings so the worker cannot act
 * on a directory it has not checked: every field here has already been
 * confined to the account's home.
 */
final class Delivery
{
    public function __construct(
        public readonly Account $account,
        public readonly string $directory,
        public readonly string $filename,
    ) {
    }

    /** The full path the file is moved to. */
    public function target(): string
    {
        return $this->directory . '/' . $this->filename;
    }

    /** The same delivery against a directory that has since been resolved. */
    public function withDirectory(string $directory): self
    {
        return new self($this->account, $directory, $this->filename);
    }
}
