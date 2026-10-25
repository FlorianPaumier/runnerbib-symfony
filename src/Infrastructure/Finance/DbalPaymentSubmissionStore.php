<?php

declare(strict_types=1);

namespace App\Infrastructure\Finance;

use App\Application\Finance\HostedCheckoutIntent;
use App\Application\Finance\HostedCheckoutSession;
use App\Application\Finance\PaymentSubmissionStore;
use Doctrine\DBAL\Connection;

final readonly class DbalPaymentSubmissionStore implements PaymentSubmissionStore
{
    public function __construct(private Connection $database, private string $providerScope)
    {
    }

    public function prepare(string $operationId, string $checkoutId, \DateTimeImmutable $now, string $worker): HostedCheckoutIntent
    {
        return $this->database->transactional(function () use ($operationId, $checkoutId, $now, $worker): HostedCheckoutIntent {
            $row = $this->database->fetchAssociative("SELECT o.checkout_id::text,o.expected_amount_cents,o.currency,o.parameters,c.state,c.initial_deadline,c.cancellation_requested_at FROM financial_operations o JOIN checkouts c ON c.id=o.checkout_id WHERE o.id=? AND o.kind='checkout_payment' AND o.provider='stripe' AND o.provider_scope=? AND o.checkout_id=? FOR UPDATE OF c", [$operationId, $this->providerScope, $checkoutId]);
            if (false === $row || !in_array($row['state'], ['pending', 'unknown'], true) || null !== $row['cancellation_requested_at']) {
                throw new \DomainException('Checkout submission is unavailable.');
            }
            if (!is_string($row['checkout_id']) || !is_int($row['expected_amount_cents']) || !is_string($row['currency']) || !is_string($row['parameters']) || !is_string($row['initial_deadline'])) {
                throw new \UnexpectedValueException('Invalid prepared payment.');
            }
            $parameters = json_decode($row['parameters'], false, 512, JSON_THROW_ON_ERROR);
            if (!$parameters instanceof \stdClass) {
                throw new \UnexpectedValueException('Invalid payment parameters.');
            }
            $attempt = $this->database->fetchAssociative('SELECT state,lease_until,fencing_generation,provider_ref FROM financial_operation_attempts WHERE operation_id=? FOR UPDATE', [$operationId]);
            if (false !== $attempt && 'succeeded' === $attempt['state'] && is_string($attempt['provider_ref']) && is_int($attempt['fencing_generation'])) {
                return new HostedCheckoutIntent($operationId, $row['checkout_id'], $row['expected_amount_cents'], $row['currency'], $parameters, $attempt['fencing_generation'], $attempt['provider_ref']);
            }
            if ($now >= new \DateTimeImmutable($row['initial_deadline'])) {
                throw new \DomainException('Initial checkout deadline has elapsed; reconcile the original payment path.');
            }
            if (false === $attempt) {
                $generation = 1;
                $this->database->executeStatement("INSERT INTO financial_operation_attempts(id,operation_id,attempt_no,provider,provider_scope,idempotency_key,state,prepared_at,submitted_at,claimed_by,lease_until,fencing_generation) VALUES(gen_random_uuid(),?,1,'stripe',?,?,'submitted',?,?,?,?,1)", [$operationId, $this->providerScope, 'checkout-payment:'.$operationId, $now->format(DATE_ATOM), $now->format(DATE_ATOM), $worker, $now->add(new \DateInterval('PT60S'))->format(DATE_ATOM)]);
                $this->database->executeStatement("INSERT INTO payment_attempts(id,checkout_id,attempt_no,expected_amount_cents,currency,state,operation_id,provider,provider_scope) VALUES(gen_random_uuid(),?,1,?,?,'prepared',?,'stripe',?)", [$row['checkout_id'], $row['expected_amount_cents'], $row['currency'], $operationId, $this->providerScope]);
            } else {
                if (!in_array($attempt['state'], ['submitted', 'unknown'], true) || !is_int($attempt['fencing_generation'])) {
                    throw new \DomainException('Original payment attempt requires reconciliation.');
                }
                if (null !== $attempt['lease_until'] && (!is_string($attempt['lease_until']) || $now < new \DateTimeImmutable($attempt['lease_until']))) {
                    throw new \DomainException('Another payment worker owns the attempt.');
                }
                $generation = $attempt['fencing_generation'] + 1;
                $this->database->executeStatement("UPDATE financial_operation_attempts SET state='submitted',claimed_by=?,lease_until=?,fencing_generation=? WHERE operation_id=?", [$worker, $now->add(new \DateInterval('PT60S'))->format(DATE_ATOM), $generation, $operationId]);
            }

            return new HostedCheckoutIntent($operationId, $row['checkout_id'], $row['expected_amount_cents'], $row['currency'], $parameters, $generation);
        });
    }

    public function unknown(HostedCheckoutIntent $intent): void
    {
        $this->database->transactional(function () use ($intent): void {
            $updated = $this->database->executeStatement("UPDATE financial_operation_attempts SET state='unknown',claimed_by=NULL,lease_until=NULL WHERE operation_id=? AND fencing_generation=? AND state='submitted'", [$intent->operationId, $intent->generation]);
            if (1 === $updated) {
                $this->database->executeStatement("UPDATE payment_attempts SET state='unknown' WHERE operation_id=?", [$intent->operationId]);
            }
        });
    }

    public function recordSession(HostedCheckoutIntent $intent, HostedCheckoutSession $session, \DateTimeImmutable $now): void
    {
        $this->database->transactional(function () use ($intent, $session, $now): void {
            $updated = $this->database->executeStatement("UPDATE financial_operation_attempts SET state='succeeded',provider_ref=?,resolved_at=?,claimed_by=NULL,lease_until=NULL WHERE operation_id=? AND fencing_generation=? AND state='submitted'", [$session->sessionReference, $now->format(DATE_ATOM), $intent->operationId, $intent->generation]);
            if (1 !== $updated) {
                throw new \DomainException('Payment worker lost its fencing generation.');
            }
            $this->database->executeStatement("UPDATE payment_attempts SET state='submitted',session_ref=? WHERE operation_id=?", [$session->sessionReference, $intent->operationId]);
        });
    }
}
