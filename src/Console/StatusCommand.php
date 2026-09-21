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
#[AsCommand(name: 'borg:status', description: 'Show the detected repository and recent job status.')]
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
            ['passphrase stored' => $config->hasPassphrase() ? 'yes' : 'no'],
            ['config file' => $this->plugin->paths->configFile()],
            ['state directory' => $this->plugin->paths->dataDir],
        );

        if (Format::currentUid() !== 0) {
            $io->warning('Not running as root. Restores cannot write into /home/<user> (0711) or set original ownership.');
        }

        if (!$runner->isInstalled()) {
            $io->error('borg is not installed.');

            return Command::FAILURE;
        }

        if ($config->isConfigured()) {
            $info = $this->plugin->repository()->info();
            if ($info->isSuccessful()) {
                $json = $info->json();
                $io->definitionList(
                    ['repository id' => (string) ($json['repository']['id'] ?? 'unknown')],
                    ['encryption' => (string) ($json['encryption']['mode'] ?? 'unknown')],
                );
            } else {
                $io->error('Repository unreadable: ' . $info->errorMessage());
            }

            $listing = $this->plugin->repository()->listArchives();

            if ($listing['result']->isSuccessful()) {
                $io->section(\sprintf('Backups (%d)', \count($listing['archives'])));
                $rows = [];
                foreach (\array_slice($listing['archives'], 0, 10) as $archive) {
                    $rows[] = [$archive->name, Format::dateTime($archive->time), Format::age($archive->time)];
                }
                if ($rows !== []) {
                    $io->table(['Backup', 'Created', 'Age'], $rows);
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
