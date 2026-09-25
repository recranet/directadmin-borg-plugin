<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Config;

use Recranet\DirectAdminBorg\Paths;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Loads and saves the plugin configuration.
 *
 * Writes go through Filesystem::dumpFile, which writes to a temporary file and
 * renames it into place: a crash mid-write can never leave a truncated config
 * behind, and a concurrent reader never sees a partial file.
 */
final class ConfigRepository
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Filesystem $filesystem,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function load(): Configuration
    {
        $stored = $this->readJson($this->paths->configFile());

        // Unknown keys are dropped rather than carried along, so a downgraded
        // plugin cannot be fed settings it does not understand.
        $values = array_merge(
            Configuration::DEFAULTS,
            array_intersect_key($stored, Configuration::DEFAULTS)
        );

        return new Configuration($this->coerce($values), $this->readPassphrase());
    }

    /**
     * Check a partial set of submitted values without storing anything.
     *
     * Split out of save() so a caller can reject input on syntax before doing
     * expensive work with it -- the repository field is probed with `borg info`
     * before it is saved, and there is no point shelling out for a value that
     * is not a repository location at all.
     *
     * @param array<string,mixed> $input
     *
     * @return string[] validation messages; empty means the input is acceptable
     */
    public function validate(array $input): array
    {
        $candidate = $this->load()->toArray();
        foreach ($input as $key => $value) {
            if (\array_key_exists($key, Configuration::DEFAULTS)) {
                $candidate[$key] = $value;
            }
        }
        $candidate = $this->coerce($candidate);

        $errors = [];
        $rules = ConfigConstraints::rules();

        // Validate only the fields actually being changed, so an existing
        // invalid value elsewhere cannot block an unrelated edit.
        foreach (array_keys($input) as $key) {
            if (!isset($rules[$key]) || !\array_key_exists($key, $candidate)) {
                continue;
            }
            foreach ($this->validator->validate($candidate[$key], $rules[$key]) as $violation) {
                $errors[] = (string) $violation->getMessage();
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Validate and persist a partial set of submitted values.
     *
     * Nothing is written unless every field passes: a half-applied
     * configuration could point backups at the wrong repository.
     *
     * @param array<string,mixed> $input
     *
     * @return string[] validation messages; empty means the change was saved
     */
    public function save(array $input): array
    {
        $current = $this->load()->toArray();

        $candidate = $current;
        foreach ($input as $key => $value) {
            if (\array_key_exists($key, Configuration::DEFAULTS)) {
                $candidate[$key] = $value;
            }
        }
        $candidate = $this->coerce($candidate);

        $errors = $this->validate($input);
        if ($errors !== []) {
            return $errors;
        }

        $this->filesystem->dumpFile(
            $this->paths->configFile(),
            json_encode($candidate, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n"
        );
        $this->filesystem->chmod($this->paths->configFile(), 0600);

        return [];
    }

    public function setPassphrase(string $passphrase): void
    {
        if ($passphrase === '') {
            $this->filesystem->remove($this->paths->passphraseFile());

            return;
        }

        $this->filesystem->dumpFile($this->paths->passphraseFile(), $passphrase . "\n");
        $this->filesystem->chmod($this->paths->passphraseFile(), 0600);
    }

    private function readPassphrase(): string
    {
        $file = $this->paths->passphraseFile();
        if (!is_file($file)) {
            return '';
        }

        return rtrim((string) @file_get_contents($file), "\r\n");
    }

    /**
     * Normalise submitted values into the types the constraints expect.
     *
     * Form input arrives as strings, so the checkboxes have to be cast to bool
     * before Assert\Type would ever pass.
     *
     * @param array<string,mixed> $values
     *
     * @return array<string,mixed>
     */
    private function coerce(array $values): array
    {
        foreach (['restore_admin_backup', 'user_restore_enabled', 'index_files'] as $key) {
            $values[$key] = $this->toBool($values[$key] ?? false);
        }
        foreach (['repository', 'ssh_command', 'admin_backups_dir', 'archive_date_format', 'jobs_paused_from', 'jobs_paused_until'] as $key) {
            $values[$key] = trim((string) ($values[$key] ?? ''));
        }

        return $values;
    }

    private function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return \in_array(strtolower((string) $value), ['1', 'on', 'yes', 'true'], true);
    }

    /** @return array<string,mixed> */
    private function readJson(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $raw = (string) @file_get_contents($file);
        if (trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // A corrupt config falls back to defaults rather than taking the
            // whole plugin down; the UI then shows an unconfigured plugin.
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }
}
