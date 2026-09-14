<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Console;

use Recranet\DirectAdminBorg\Job\Job;
use Recranet\DirectAdminBorg\Plugin;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backup triggered by a DirectAdmin hook.
 *
 * Always exits 0: a hook must never fail the DirectAdmin operation that called
 * it, and it does nothing at all unless the admin opted in.
 */
#[AsCommand(name: 'borg:hook-backup', description: 'Start a backup from a DirectAdmin hook, if enabled.')]
final class HookBackupCommand extends Command
{
    public function __construct(private readonly Plugin $plugin)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('trigger', InputArgument::OPTIONAL, 'Name of the hook', 'hook');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->plugin->config()->load();

            if (!$config->runAfterDaBackups() || !$config->isConfigured()) {
                return Command::SUCCESS;
            }

            $job = $this->plugin->jobs()->create(Job::TYPE_BACKUP, 'hook', [
                'prune'   => $config->pruneEnabled(),
                'trigger' => (string) $input->getArgument('trigger'),
            ]);

            $this->plugin->dispatcher()->dispatch($job);
            $output->writeln(sprintf('Started job %s', $job->id));
        } catch (\Throwable $e) {
            $output->writeln('<comment>Borg hook skipped: ' . $e->getMessage() . '</comment>');
        }

        return Command::SUCCESS;
    }
}
