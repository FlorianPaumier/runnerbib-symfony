<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Finance\ReconcileCheckout;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:checkout:reconcile', description: 'Reconcile due checkouts using their original payment path.')]
final class ReconcileCheckoutsCommand extends Command
{
    public function __construct(private readonly ReconcileCheckout $checkouts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of checkouts to reconcile.', '100');
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
            $result = $this->checkouts->reconcileDue(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 'scheduler-'.bin2hex(random_bytes(16)), $limit);
        } catch (\Throwable) {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('Checkout reconciliation failed; payment protection retained for retry.');

            return self::FAILURE;
        }
        $output->writeln('Reconciled checkouts: '.$result['reconciled'].($result['failed'] > 0 ? ', failed: '.$result['failed'] : ''));

        return 0 === $result['failed'] ? self::SUCCESS : self::FAILURE;
    }
}
