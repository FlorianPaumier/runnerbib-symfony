<?php

declare(strict_types=1);

namespace App\Infrastructure\Finance;

use App\Application\Finance\CheckoutReconciliation;
use App\Application\Finance\CheckoutReconciliationStore;
use App\Domain\Finance\HostedPaymentState;
use Doctrine\DBAL\Connection;

final readonly class DbalCheckoutReconciliationStore implements CheckoutReconciliationStore
{
    public function __construct(private Connection $database, private string $providerScope)
    {
    }

    public function due(\DateTimeImmutable $now, int $limit): array
    {
        $ids = [];
        foreach ($this->database->fetchFirstColumn("SELECT c.id::text FROM checkouts c JOIN financial_operations o ON o.checkout_id=c.id AND o.kind='checkout_payment' AND o.provider='stripe' AND o.provider_scope=? LEFT JOIN payment_attempts p ON p.operation_id=o.id WHERE c.state IN ('pending','processing','unknown') AND (c.initial_deadline<=? OR c.cancellation_requested_at IS NOT NULL) AND (c.lease_until IS NULL OR c.lease_until<=?) AND (p.last_reconciled_at IS NULL OR c.cancellation_requested_at IS NULL OR p.last_reconciled_at<c.cancellation_requested_at OR p.last_reconciled_at<=?) ORDER BY COALESCE(p.last_reconciled_at,c.authorized_at),c.id LIMIT ?", [$this->providerScope, $now->format(DATE_ATOM), $now->format(DATE_ATOM), $now->sub(new \DateInterval('PT60S'))->format(DATE_ATOM), $limit], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::INTEGER]) as $id) {
            if (!is_string($id)) {
                throw new \UnexpectedValueException('Invalid due checkout identity.');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    public function claim(string $checkoutId, \DateTimeImmutable $now, string $worker, ?string $operationId = null): CheckoutReconciliation
    {
        return $this->database->transactional(function () use ($checkoutId, $now, $worker, $operationId): CheckoutReconciliation {
            $this->lockRaces($checkoutId);
            $row = $this->database->fetchAssociative("SELECT c.state,c.cancellation_requested_at,c.expected_total_cents,c.currency,c.initial_deadline,c.lease_until,c.fencing_generation,o.id::text AS operation_id,o.parameters,p.session_ref,(p.id IS NOT NULL OR EXISTS(SELECT 1 FROM financial_operation_attempts f WHERE f.operation_id=o.id)) AS attempted FROM checkouts c JOIN financial_operations o ON o.checkout_id=c.id AND o.kind='checkout_payment' AND o.provider='stripe' AND o.provider_scope=? LEFT JOIN payment_attempts p ON p.operation_id=o.id WHERE c.id=? AND (?::uuid IS NULL OR (o.id=?::uuid AND c.cancellation_requested_at IS NOT NULL)) FOR UPDATE OF c", [$this->providerScope, $checkoutId, $operationId, $operationId]);
            if (false === $row || (!in_array($row['state'], ['pending', 'processing', 'unknown'], true) && !(null !== $operationId && 'cancelled' === $row['state']))) {
                throw new \DomainException('Checkout reconciliation is unavailable.');
            }
            if (null !== $row['lease_until'] && (!is_string($row['lease_until']) || $now < new \DateTimeImmutable($row['lease_until']))) {
                throw new \DomainException('Another checkout worker owns reconciliation.');
            }
            if (!is_int($row['expected_total_cents']) || !is_string($row['currency']) || !is_string($row['initial_deadline']) || !is_int($row['fencing_generation']) || !is_string($row['operation_id']) || !is_string($row['parameters']) || (null !== $row['session_ref'] && !is_string($row['session_ref']))) {
                throw new \UnexpectedValueException('Invalid original checkout path.');
            }
            $parameters = json_decode($row['parameters'], false, 512, JSON_THROW_ON_ERROR);
            if (!$parameters instanceof \stdClass || !($parameters->provider_configuration ?? null) instanceof \stdClass || !is_string($parameters->provider_configuration->api_version ?? null)) {
                throw new \UnexpectedValueException('Missing committed provider version.');
            }
            if ('cancelled' === $row['state']) {
                return new CheckoutReconciliation($checkoutId, $row['operation_id'], $row['expected_total_cents'], $row['currency'], $row['session_ref'], $row['fencing_generation'], new \DateTimeImmutable($row['initial_deadline']), $parameters->provider_configuration->api_version, true === $row['attempted'], true, true);
            }
            $generation = $row['fencing_generation'] + 1;
            $this->database->executeStatement('UPDATE checkouts SET claimed_by=?,lease_until=?,fencing_generation=?,cancellation_requested_at=CASE WHEN initial_deadline<=?::timestamptz THEN COALESCE(cancellation_requested_at,?::timestamptz) ELSE cancellation_requested_at END WHERE id=?', [$worker, $now->add(new \DateInterval('PT60S'))->format(DATE_ATOM), $generation, $now->format(DATE_ATOM), $now->format(DATE_ATOM), $checkoutId]);

            return new CheckoutReconciliation($checkoutId, $row['operation_id'], $row['expected_total_cents'], $row['currency'], $row['session_ref'], $generation, new \DateTimeImmutable($row['initial_deadline']), $parameters->provider_configuration->api_version, true === $row['attempted'], null !== $row['cancellation_requested_at']);
        });
    }

    public function finish(CheckoutReconciliation $claim, HostedPaymentState $state, \DateTimeImmutable $now): void
    {
        $this->database->transactional(function () use ($claim, $state, $now): void {
            $this->lockRaces($claim->checkoutId);
            $row = $this->database->fetchAssociative('SELECT state,fencing_generation FROM checkouts WHERE id=? FOR UPDATE', [$claim->checkoutId]);
            if (false === $row || $row['fencing_generation'] !== $claim->generation || !in_array($row['state'], ['pending', 'processing', 'unknown'], true)) {
                throw new \DomainException('Checkout worker lost its fencing generation.');
            }
            $closed = HostedPaymentState::Closed === $state;
            $next = $closed ? 'cancelled' : (in_array($state, [HostedPaymentState::Paid, HostedPaymentState::Processing], true) ? 'processing' : (HostedPaymentState::Unknown === $state ? 'unknown' : 'pending'));
            $this->database->executeStatement('UPDATE checkouts SET state=?,claimed_by=NULL,lease_until=NULL,version=version+1 WHERE id=?', [$next, $claim->checkoutId]);
            $this->database->executeStatement("UPDATE checkout_items SET reconciliation_state=? WHERE checkout_id=? AND protection_state='protected'", [$closed ? 'resolved' : (HostedPaymentState::Open === $state ? 'pending' : $next), $claim->checkoutId]);
            $this->database->executeStatement('UPDATE payment_attempts SET last_reconciled_at=? WHERE operation_id=?', [$now->format(DATE_ATOM), $claim->operationId]);
            if ($closed) {
                $this->database->executeStatement("UPDATE checkout_items SET protection_state='released',released_at=? WHERE checkout_id=? AND protection_state='protected'", [$now->format(DATE_ATOM), $claim->checkoutId]);
                $this->database->executeStatement('UPDATE buyer_race_guards SET reserved_checkout_id=NULL,version=version+1 WHERE reserved_checkout_id=?', [$claim->checkoutId]);
                $this->database->executeStatement("UPDATE payment_attempts SET state='cancelled' WHERE operation_id=?", [$claim->operationId]);
            }
        });
    }

    private function lockRaces(string $checkoutId): void
    {
        $this->database->fetchFirstColumn('SELECT id FROM races WHERE id IN (SELECT race_id FROM checkout_items WHERE checkout_id=?) ORDER BY id FOR UPDATE', [$checkoutId]);
    }
}
