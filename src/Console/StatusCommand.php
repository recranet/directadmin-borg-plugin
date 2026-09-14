<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Console;

use Recranet\DirectAdminBorg\Job\Job;
use Recranet\DirectAdminBorg\Plugin;
use Recranet\DirectAdminBorg\Support\Format;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Shows plugin state from the shell, for diagnosing a server without the UI. */
#[AsCommand(name: 'borg:status', description: 'Show repository, schedule and recent job status.')]
final class StatusCommand extends Command
{
    public function __construct(private readonly Plugin $plugin)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $config = $this->plugin->config()->load();
        $runner = $this->plugin->repository()->runner();

        $io->title('DirectAdmin Borg plugin');

        $io->definitionList(
            ['borg binary' => $runner->binary()],
            ['borg version' => $runner->version() ?? 'not installed'],
            ['running as uid' => (string) Format::currentUid()],
            ['repository' => $config->repository() ?: 'not configured'],
            ['encryption' => $config->encryption()],
            ['passphrase stored' => $config->hasPassphrase() ? 'yes' : 'no'],
            ['schedule' => $config->describeSchedule()],
            ['cron file' => $this->plugin->cron()->isInstalled() ? $this->plugin->cron()->file() : 'not installed'],
            ['state directory' => $this->plugin->paths->dataDir],
        );

        if (Format::currentUid() !== 0) {
            $io->warning('Not running as root. Backups cannot read /home/<user> (0711) or /etc/shadow.');
        }

        if (!$runner->isInstalled()) {
            $io->error('borg is not installed.');

            return Command::FAILURE;
        }

        if ($config->isConfigured()) {
            $listing = $this->plugin->repository()->listArchives();

            if ($listing['result']->isSuccessful()) {
                $io->section(sprintf('Archives (%d)', \count($listing['archives'])));
                $rows = [];
                foreach (\array_slice($listing['archives'], 0, 10) as $archive) {
                    $rows[] = [$archive->name, Format::dateTime($archive->time), Format::age($archive->time)];
                }
                if ($rows !== []) {
                    $io->table(['Archive', 'Created', 'Age'], $rows);
                }
            } else {
                $io->error('Repository unreadable: ' . $listing['result']->errorMessage());
            }
        }

        $jobs = $this->plugin->jobs()->recent(10);
        if ($jobs !== []) {
            $io->section('Recent jobs');
            $io->table(
                ['Job', 'Type', 'Status', 'Result'],
                array_map(static fn (Job $job) => [$job->id, $job->type(), $job->status(), $job->message()], $jobs)
            );
        }

        return Command::SUCCESS;
    }
}
