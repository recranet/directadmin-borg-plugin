<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Borg;

/** Outcome of a single borg invocation. */
final class BorgResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly string $commandLine,
        public readonly bool $timedOut = false
    ) {
    }

    /**
     * Borg uses exit code 1 for warnings — a file that changed while being
     * read, a path that could not be opened — and still writes a valid archive,
     * so a warning counts as success with a caveat rather than a failure.
     */
    public function isSuccessful(): bool
    {
        return $this->exitCode === 0 || $this->exitCode === 1;
    }

    public function isWarning(): bool
    {
        return $this->exitCode === 1;
    }

    public function errorMessage(): string
    {
        $message = trim($this->stderr);
        if ($message === '') {
            $message = trim($this->stdout);
        }
        if ($message === '') {
            $message = $this->timedOut
                ? 'borg timed out.'
                : sprintf('borg exited with code %d.', $this->exitCode);
        }

        // borg prints a full python traceback on some errors; the last line is
        // the part an operator can act on.
        if (str_contains($message, 'Traceback (most recent call last)')) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $message))));
            $message = end($lines) ?: $message;
        }

        return $message;
    }

    /** @return array<int,array<string,mixed>> one decoded object per output line */
    public function jsonLines(int $limit = PHP_INT_MAX): array
    {
        $rows = [];
        foreach (explode("\n", $this->stdout) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (\is_array($decoded)) {
                $rows[] = $decoded;
                if (\count($rows) >= $limit) {
                    break;
                }
            }
        }

        return $rows;
    }

    public function json(): array
    {
        $decoded = json_decode($this->stdout, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
