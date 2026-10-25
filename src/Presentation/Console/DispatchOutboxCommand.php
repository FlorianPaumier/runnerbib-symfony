<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Operations\DispatchOutbox;
use App\Application\Operations\OutboxDispatchOutcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:outbox:dispatch', description: 'Queue committed outbox intents for their registered handlers.')]
final class DispatchOutboxCommand extends Command
{
    public function __construct(private readonly DispatchOutbox $dispatch)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of intents to queue.', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $value = $input->getOption('limit');
        $limit = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        if (false === $limit || $limit < 1 || $limit > 1000) {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('Limit must be between 1 and 1000.');

            return self::INVALID;
        }
        $worker = 'cli-'.bin2hex(random_bytes(16));
        $queued = 0;
        $deferred = 0;
        for ($index = 0; $index < $limit; ++$index) {
            try {
                $outcome = $this->dispatch->dispatchNext(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $worker);
            } catch (\Throwable $exception) {
                ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('Dispatch failed; intent retained for retry.');

                return self::FAILURE;
            }
            if (OutboxDispatchOutcome::Empty === $outcome) {
                break;
            }
            if (OutboxDispatchOutcome::Deferred === $outcome) {
                ++$deferred;

                continue;
            }
            ++$queued;
        }
        $output->writeln('Queued messages: '.$queued.($deferred > 0 ? ', deferred: '.$deferred : ''));

        return 0 === $deferred ? self::SUCCESS : self::FAILURE;
    }
}
