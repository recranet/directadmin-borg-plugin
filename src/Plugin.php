<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg;

use Recranet\DirectAdminBorg\Borg\ArchiveIndex;
use Recranet\DirectAdminBorg\Borg\BorgRunner;
use Recranet\DirectAdminBorg\Borg\Repository;
use Recranet\DirectAdminBorg\Config\ConfigRepository;
use Recranet\DirectAdminBorg\Job\BackupWindow;
use Recranet\DirectAdminBorg\Job\JobDispatcher;
use Recranet\DirectAdminBorg\Job\JobRepository;
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

    // Typed properties rather than a service map: a map keyed by class-string
    // hands back `object` and loses every return type in this file.
    private ?Filesystem $filesystem = null;
    private ?ValidatorInterface $validator = null;
    private ?ConfigRepository $config = null;
    private ?BorgRunner $borg = null;
    private ?JobRepository $jobs = null;
    private ?JobDispatcher $dispatcher = null;
    private ?LockFactory $lockFactory = null;
    private ?TemplateRenderer $renderer = null;

    private function __construct(
        public readonly string $pluginDir,
        public readonly Paths $paths,
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
        return $this->filesystem ??= new Filesystem();
    }

    public function validator(): ValidatorInterface
    {
        return $this->validator ??= Validation::createValidator();
    }

    public function config(): ConfigRepository
    {
        return $this->config ??= new ConfigRepository(
            $this->paths,
            $this->filesystem(),
            $this->validator()
        );
    }

    public function borg(): BorgRunner
    {
        return $this->borg ??= new BorgRunner(
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

    /**
     * Not memoised, for the same reason as repository(): it is scoped to the
     * configured repository, and a request that changes that must not keep
     * reading the previous one's indexes.
     */
    public function archiveIndex(): ArchiveIndex
    {
        return new ArchiveIndex(
            $this->paths,
            $this->filesystem(),
            $this->config()->load()->repository()
        );
    }

    public function jobs(): JobRepository
    {
        return $this->jobs ??= new JobRepository($this->paths, $this->filesystem());
    }

    /** Not memoised: it is read from the configuration, which a request can change. */
    public function backupWindow(): BackupWindow
    {
        return BackupWindow::fromConfiguration($this->config()->load());
    }

    public function dispatcher(): JobDispatcher
    {
        return $this->dispatcher ??= new JobDispatcher($this->pluginDir);
    }

    /**
     * Lock guarding repository-wide operations.
     *
     * Borg locks the repository itself, but acquiring here means the UI can say
     * "a check is already running" instead of surfacing a borg lock error.
     * Restores do not take it: they must stay possible while a check, or the
     * server's own backup run, has the repository busy.
     */
    public function lockFactory(): LockFactory
    {
        return $this->lockFactory ??= new LockFactory(new FlockStore($this->paths->locksDir()));
    }

    public function renderer(): TemplateRenderer
    {
        return $this->renderer ??= new TemplateRenderer(
            $this->pluginDir . '/templates',
            $this->paths->cacheDir()
        );
    }
}
