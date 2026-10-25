<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Finance\ProcessProviderEvents;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:provider-events:process', description: 'Refresh the checkout state of the authenticated payment provider events.')]
final class ProcessProviderEventsCommand extends Command
{
    public function __construct(private readonly ProcessProviderEvents $events)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of provider events to process.', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $value = $input->getOption('limit');
        $limit = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        if (false === $limit || $limit < 1 || $limit > 1000) {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('Limit must be between 1 and 1000.');

            return self::INVALID;
        }
        try {
            $result = $this->events->processDue(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 'provider-events-'.bin2hex(random_bytes(16)), $limit);
        } catch (\Throwable) {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('Provider event processing failed; the events stay pending for the next cycle.');

            return self::FAILURE;
        }
        $output->writeln('Provider events processed: '.$result['processed'].', ignored: '.$result['ignored'].', failed: '.$result['failed']);

        return 0 === $result['failed'] ? self::SUCCESS : self::FAILURE;
    }
}
