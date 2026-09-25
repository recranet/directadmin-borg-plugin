<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Job;

use Recranet\DirectAdminBorg\Exception\BorgPluginException;
use Recranet\DirectAdminBorg\Plugin;
use Recranet\DirectAdminBorg\Security\Account;

/**
 * Restore one of an account's two directories back over itself.
 *
 * These are the two requests that actually come in -- "the site is broken" and
 * "the mail is gone" -- so each is one button rather than a path to be typed.
 * Both levels ask for exactly the same thing, so both ask for it here: an
 * administrator naming an account, and a customer who can only ever be naming
 * themselves. Sharing the code is the point. The boundary a customer restore
 * depends on is the same boundary an admin restore uses, and two copies of it
 * would be two things to keep right.
 *
 * There is no destination anywhere in this: restoring /home/alice/domains
 * anywhere but /home/alice/domains produces a copy nobody asked for, which then
 * has to be moved by hand with the right ownership. In place is the only answer
 * that finishes the job.
 */
final class AccountTreeRestore
{
    /** The directories a restore is offered for, and what each is called. */
    public const TREES = [
        'domains' => 'Domains',
        'imap'    => 'Email',
    ];

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public static function isTree(string $tree): bool
    {
        return isset(self::TREES[$tree]);
    }

    public static function label(string $tree): string
    {
        return self::TREES[$tree] ?? $tree;
    }

    /** The absolute path a restore of $tree writes back to. */
    public static function path(Account $account, string $tree): string
    {
        return $account->home . '/' . $tree;
    }

    /**
     * Queue the restore and hand it to the worker.
     *
     * $owner is who the job belongs to for the purposes of who may watch it --
     * the administrator who started it, or the customer themselves -- which is
     * not the same question as whose files these are.
     *
     * $cleanPaths is the malware case, and it is a parameter rather than a
     * given because it is never implied: a restore on its own overwrites and
     * leaves everything else alone, which is why the file an attacker added
     * survives one. Deleting first is the only thing that makes the directory
     * exactly what the archive held, and it takes everything added since with
     * it. Both levels ask for it the same way -- tick it, and type the account
     * name -- because the mistake is the same mistake whoever is logged in.
     *
     * @param string[] $cleanPaths directories to delete before extracting
     */
    public function queue(
        string $archive,
        Account $account,
        string $tree,
        string $owner,
        string $trigger,
        array $cleanPaths = [],
    ): Job {
        if (!self::isTree($tree)) {
            throw new BorgPluginException('Not a restorable directory: ' . $tree);
        }

        // Confined here as well as in the caller. This is the only place that
        // decides what path the job carries, so it is the place the boundary
        // has to hold -- and the worker checks it again at the point of use.
        $target = $account->confine(self::path($account, $tree));

        $clean = [];
        foreach ($cleanPaths as $path) {
            $path = $account->confine($path);
            if ($path === rtrim($account->home, '/')) {
                throw new BorgPluginException('Refusing to delete the whole home directory.');
            }
            $clean[] = $path;
        }

        $this->plugin->backupWindow()->assertOpen();

        $job = $this->plugin->jobs()->create(Job::TYPE_RESTORE, $owner, array_filter([
            'archive' => $archive,
            'paths'   => [$target],
            // borg strips the leading slash and writes relative to the working
            // directory, so "/" is what puts a directory back where it came
            // from.
            'destination' => '/',
            'in_place'    => true,
            // Not chown_to: the worker writes an in-place restore as this
            // account, never as root, so the files come back owned by it
            // without a chown. confine_to is what makes the worker re-resolve
            // this account and re-check the path before it writes anything,
            // and it is also who the deletion above is done as.
            'confine_to'   => $account->username,
            'restore_user' => $account->username,
            'clean_paths'  => $clean !== [] ? $clean : null,
            'trigger'      => $trigger,
        ], static fn ($value) => $value !== null));

        $this->plugin->dispatcher()->dispatch($job);

        return $job;
    }
}
