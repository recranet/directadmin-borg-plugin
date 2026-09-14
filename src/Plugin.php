<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg;

use Recranet\DirectAdminBorg\Borg\BorgRunner;
use Recranet\DirectAdminBorg\Borg\Repository;
use Recranet\DirectAdminBorg\Config\ConfigRepository;
use Recranet\DirectAdminBorg\Job\JobDispatcher;
use Recranet\DirectAdminBorg\Job\JobRepository;
use Recranet\DirectAdminBorg\Schedule\CronWriter;
use Recranet\DirectAdminBorg\Ui\TemplateRenderer;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Wiring for the plugin.
 *
 * A hand-rolled container rather than symfony/dependency-injection: the plugin
 * has a dozen services and no compilation step, and DirectAdmin re-executes the
 * whole process for every request, so a compiled container would cost more than
 * it saves.
 */
final class Plugin
{
    public const NAME = 'borg';

    private array $services = [];

    private function __construct(
        public readonly string $pluginDir,
        public readonly Paths $paths
    ) {
    }

    public static function boot(?string $pluginDir = null): self
    {
        $pluginDir ??= \dirname(__DIR__);

        $plugin = new self($pluginDir, Paths::fromEnvironment());
        $plugin->paths->ensure($plugin->filesystem());

        return $plugin;
    }

    public function filesystem(): Filesystem
    {
        return $this->services[Filesystem::class] ??= new Filesystem();
    }

    public function validator(): ValidatorInterface
    {
        return $this->services[ValidatorInterface::class] ??= Validation::createValidator();
    }

    public function config(): ConfigRepository
    {
        return $this->services[ConfigRepository::class] ??= new ConfigRepository(
            $this->paths,
            $this->filesystem(),
            $this->validator()
        );
    }

    public function borg(): BorgRunner
    {
        return $this->services[BorgRunner::class] ??= new BorgRunner(
            BorgRunner::locateBinary(),
            $this->paths->borgHome
        );
    }

    /**
     * Deliberately not memoised: a Repository captures the configuration it was
     * built with, and a request that saves new settings must not keep talking
     * to the old repository for the rest of that request. The expensive part —
     * probing the borg binary for its version — lives in the memoised runner.
     */
    public function repository(): Repository
    {
        return new Repository($this->borg(), $this->config()->load());
    }

    public function jobs(): JobRepository
    {
        return $this->services[JobRepository::class] ??= new JobRepository($this->paths, $this->filesystem());
    }

    public function dispatcher(): JobDispatcher
    {
        return $this->services[JobDispatcher::class] ??= new JobDispatcher($this->pluginDir, $this->paths);
    }

    public function cron(): CronWriter
    {
        return $this->services[CronWriter::class] ??= new CronWriter(
            $this->pluginDir,
            $this->paths,
            $this->filesystem()
        );
    }

    /**
     * Lock guarding every operation that writes to the borg repository.
     *
     * Borg locks the repository itself, but acquiring here means the UI can say
     * "a backup is already running" instead of surfacing a borg lock error, and
     * a scheduled run overlapping a manual one becomes a no-op rather than a
     * failure.
     */
    public function lockFactory(): LockFactory
    {
        return $this->services[LockFactory::class] ??= new LockFactory(new FlockStore($this->paths->locksDir()));
    }

    public function renderer(): TemplateRenderer
    {
        return $this->services[TemplateRenderer::class] ??= new TemplateRenderer(
            $this->pluginDir . '/templates',
            $this->paths->cacheDir()
        );
    }
}
