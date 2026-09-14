<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Console;

use Recranet\DirectAdminBorg\Job\Job;
use Recranet\DirectAdminBorg\Job\JobRunner;
use Recranet\DirectAdminBorg\Plugin;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Executes a queued job. This is what the detached worker process runs. */
#[AsCommand(name: 'borg:job', description: 'Run a queued job to completion.')]
final class RunJobCommand extends Command
{
    public function __construct(private readonly Plugin $plugin)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('job', InputArgument::REQUIRED, 'Job id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('job');

        if (!Job::isValidId($id)) {
            $output->writeln('<error>Malformed job id.</error>');

            return Command::INVALID;
        }

        $job = $this->plugin->jobs()->find($id);
        if ($job === null) {
            $output->writeln(\sprintf('<error>Unknown job: %s</error>', $id));

            return Command::INVALID;
        }

        // Only a freshly queued job may start, so a re-run of the same id
        // cannot restart a backup that is already in flight or finished.
        if ($job->status() !== Job::STATUS_QUEUED) {
            $output->writeln(\sprintf('<comment>Job %s is %s, not queued.</comment>', $id, $job->status()));

            return Command::INVALID;
        }

        return (new JobRunner($this->plugin, $this->plugin->jobs()))->run($job);
    }
}
