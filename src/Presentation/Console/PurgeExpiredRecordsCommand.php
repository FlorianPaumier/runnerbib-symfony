<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Operations\PurgeExpiredRecords;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:retention:purge', description: 'Delete the records that fell out of their retention window.')]
final class PurgeExpiredRecordsCommand extends Command
{
    public function __construct(private readonly PurgeExpiredRecords $purge)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $counts = $this->purge->purge(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (\Throwable) {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('Retention purge failed; nothing was deleted.');

            return self::FAILURE;
        }
        $deleted = [];
        foreach ($counts as $table => $count) {
            if ($count > 0) {
                $deleted[] = $table.': '.$count;
            }
        }
        $output->writeln([] === $deleted ? 'Nothing to purge.' : 'Purged '.implode(', ', $deleted));

        return self::SUCCESS;
    }
}
