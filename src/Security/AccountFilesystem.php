<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Security;

use Recranet\DirectAdminBorg\Exception\BorgPluginException;

/**
 * Filesystem operations inside a customer's home, done as that customer.
 *
 * The rule this class exists for: root never writes, deletes, chmods or chowns
 * inside a customer's home. Everything that has to goes through here.
 *
 * Checking the path first cannot be made to hold. The customer owns every
 * directory below their home, so any of them can be swapped for a symlink to
 * /etc between the check and the write. borg 1.1 and 1.2 extract straight
 * through a symlinked parent, and Symfony's Filesystem is is_link() followed by
 * an operation on the same path string, re-resolved by the kernel -- for
 * remove(), once per entry as it walks. Doing it safely as root means openat()
 * with O_NOFOLLOW and operating on descriptors, which PHP does not have. Done
 * as the customer, the kernel applies the customer's own permissions at every
 * step, and a link to /etc gets them nothing they could not do from a shell.
 *
 * So a realpath() or is_link() check near a write is an early refusal with a
 * clear message, never the guard.
 *
 * Each operation is a coreutils command under setpriv: numeric ids read from
 * /etc/passwd, no PAM stack, and no supplementary groups. Keeping those would be
 * worse than useless -- a customer in the mail group would be writing with
 * access to every other account's mailbox. util-linux and coreutils are on
 * every EL server, and there is no fallback when one is missing: without them
 * the only way left is the root one.
 */
final class AccountFilesystem
{
    private const SEARCH_PATH = ['/usr/bin', '/bin', '/usr/sbin', '/sbin'];

    public function __construct(public readonly Account $account)
    {
    }

    /** Delete a file or a whole tree. A symlink is removed, never followed. */
    public function remove(string $path): void
    {
        $this->run([self::binary('rm'), '-rf', '--one-file-system', '--', $path]);
    }

    /** Create a directory and any missing parents; $mode applies to the last. */
    public function mkdir(string $path, int $mode): void
    {
        $this->run([self::binary('mkdir'), '-p', '-m', \sprintf('%o', $mode), '--', $path]);
    }

    public function chmod(string $path, int $mode): void
    {
        $this->run([self::binary('chmod'), \sprintf('%o', $mode), '--', $path]);
    }

    /**
     * Rename over whatever is at $to. A symlink there is replaced, not written
     * through: rename() swaps the directory entry.
     */
    public function rename(string $from, string $to): void
    {
        $this->run([self::binary('mv'), '-f', '-T', '--', $from, $to]);
    }

    /**
     * Unpack a tar read from stdin into $directory, for BorgRunner::runInto().
     *
     * Permissions come from the archive; ownership is whoever runs it, which is
     * the point.
     *
     * @return list<string>
     */
    public function untarCommand(string $directory): array
    {
        return $this->command([
            self::binary('tar'),
            '--extract',
            '--file=-',
            '--directory=' . $directory,
            '--preserve-permissions',
            '--no-same-owner',
        ]);
    }

    /**
     * Write stdin to $path, for BorgRunner::runInto(). dd rather than tee,
     * which would copy every byte to stdout and so into the job log.
     *
     * @return list<string>
     */
    public function writeCommand(string $path): array
    {
        return $this->command([self::binary('dd'), 'of=' . $path, 'bs=1M', 'status=none']);
    }

    /**
     * @param string[] $command absolute path first
     *
     * @return list<string>
     */
    public function command(array $command): array
    {
        return array_merge([
            self::binary('setpriv'),
            '--reuid=' . $this->account->uid,
            '--regid=' . $this->account->gid,
            '--clear-groups',
            '--no-new-privs',
            '--',
        ], array_values($command));
    }

    /**
     * The whole environment of the process, not additions to the worker's.
     *
     * The account owns the process once setpriv has run, so it can read
     * /proc/<pid>/environ. The worker's own environment came from a DirectAdmin
     * request -- an administrator's, when an administrator started the job --
     * and on the other end of a pipe borg holds BORG_PASSPHRASE in its.
     * Nothing of either is passed on.
     *
     * @return array<string,string>
     */
    public function environment(): array
    {
        return [
            'PATH'   => '/usr/bin:/bin',
            'HOME'   => $this->account->home,
            'LANG'   => 'C.UTF-8',
            'LC_ALL' => 'C.UTF-8',
        ];
    }

    /**
     * A system binary by absolute path, from the system directories only.
     *
     * Not a PATH lookup: the worker inherits its environment from a DirectAdmin
     * request, and which binary root hands a customer's files to is not
     * something that environment should get a say in.
     */
    public static function binary(string $name): string
    {
        foreach (self::SEARCH_PATH as $directory) {
            if (is_file($directory . '/' . $name) && is_executable($directory . '/' . $name)) {
                return $directory . '/' . $name;
            }
        }

        throw new BorgPluginException(\sprintf('%s is not installed, so nothing can be restored into an account safely.', $name));
    }

    /**
     * proc_open with an exact environment, not Symfony Process, which merges
     * the parent's into whatever it is given. See environment().
     *
     * @param string[] $command
     */
    private function run(array $command): void
    {
        $process = proc_open(
            $this->command($command),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            '/',
            $this->environment()
        );
        if (!\is_resource($process)) {
            throw new BorgPluginException('Could not start ' . basename($command[0] ?? 'a command') . '.');
        }

        $stderr = (string) stream_get_contents($pipes[2], 65536);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new BorgPluginException(trim($stderr) ?: basename($command[0] ?? 'a command') . ' failed.');
        }
    }
}
