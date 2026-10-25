<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Notifications\RunAvailabilityBatch;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:availability:run', description: 'Process due availability notifications in bounded chunks.')]
final class RunAvailabilityCommand extends Command
{
    public function __construct(private readonly RunAvailabilityBatch $batches)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of batch chunks (100 notifications each).', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $value = $input->getOption('limit');
        $limit = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        if (false === $limit || $limit < 1 || $limit > 1000) {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('Limit must be between 1 and 1000.');

            return self::INVALID;
        }
        $processed = 0;
        $failed = 0;
        for ($index = 0; $index < $limit; ++$index) {
            try {
                if (!$this->batches->runNext(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))) {
                    break;
                }
            } catch (\Throwable) {
                ++$failed;

                continue;
            }
            ++$processed;
        }
        $output->writeln('Processed batch chunks: '.$processed.($failed > 0 ? ', failed: '.$failed : ''));

        return 0 === $failed ? self::SUCCESS : self::FAILURE;
    }
}
