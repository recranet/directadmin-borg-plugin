<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Console;

use Recranet\DirectAdminBorg\Job\Job;
use Recranet\DirectAdminBorg\Job\JobRunner;
use Recranet\DirectAdminBorg\Plugin;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The scheduled backup, invoked from /etc/cron.d/directadmin-borg.
 *
 * Runs in the foreground so cron's exit status reflects the backup, and so two
 * overlapping runs cannot start: the repository lock is held for the duration.
 */
#[AsCommand(name: 'borg:scheduled-backup', description: 'Run the scheduled backup (used by cron).')]
final class ScheduledBackupCommand extends Command
{
    public function __construct(private readonly Plugin $plugin)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('trigger', null, InputOption::VALUE_REQUIRED, 'What started this run', 'schedule');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->plugin->config()->load();

        if (!$config->isConfigured()) {
            $output->writeln('<comment>No repository configured; nothing to do.</comment>');

            return Command::SUCCESS;
        }

        $job = $this->plugin->jobs()->create(Job::TYPE_BACKUP, 'cron', [
            'prune'   => $config->pruneEnabled(),
            'trigger' => (string) $input->getOption('trigger'),
        ]);

        $output->writeln(\sprintf('Running job %s', $job->id));

        $exitCode = (new JobRunner($this->plugin, $this->plugin->jobs()))->run($job);

        $refreshed = $this->plugin->jobs()->find($job->id);
        if ($refreshed !== null) {
            $output->writeln(\sprintf('%s: %s', $refreshed->status(), $refreshed->message()));
        }

        return $exitCode;
    }
}
