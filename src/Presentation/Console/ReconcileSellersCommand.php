<?php

declare(strict_types=1);

namespace App\Presentation\Console;

use App\Application\Finance\ReconcileSellers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:sellers:reconcile', description: 'Re-confirm transfer readiness for sellers whose confirmation is missing or stale.')]
final class ReconcileSellersCommand extends Command
{
    public function __construct(private readonly ReconcileSellers $sellers)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of sellers to re-confirm.', '50');
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
            $result = $this->sellers->reconcileDue(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $limit);
        } catch (\Throwable) {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('Seller reconciliation failed; retry on the next cycle.');

            return self::FAILURE;
        }
        $output->writeln('Reconciled sellers: '.$result['reconciled'].', failed: '.$result['failed']);

        return 0 === $result['failed'] ? self::SUCCESS : self::FAILURE;
    }
}
