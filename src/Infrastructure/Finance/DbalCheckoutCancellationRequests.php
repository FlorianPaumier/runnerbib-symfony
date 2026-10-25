<?php

declare(strict_types=1);

namespace App\Infrastructure\Finance;

use App\Application\Finance\CheckoutCancellationRequests;
use App\Application\Operations\OutboxMessage;
use App\Application\Operations\OutboxWriter;
use Doctrine\DBAL\Connection;

final readonly class DbalCheckoutCancellationRequests implements CheckoutCancellationRequests
{
    public function __construct(private Connection $database, private OutboxWriter $outbox)
    {
    }

    public function requestBuyer(string $buyerId, \DateTimeImmutable $now): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new \LogicException('An owning cancellation transaction and sorted race locks are required.');
        }
        $rows = $this->database->fetchAllAssociative("SELECT c.id::text,c.version,o.id::text AS operation_id FROM checkouts c JOIN financial_operations o ON o.checkout_id=c.id AND o.kind='checkout_payment' WHERE c.buyer_id=? AND c.state IN ('pending','processing','unknown') AND c.cancellation_requested_at IS NULL AND EXISTS(SELECT 1 FROM checkout_items i WHERE i.checkout_id=c.id AND i.protection_state='protected') ORDER BY c.id FOR UPDATE OF c", [$buyerId]);
        foreach ($rows as $row) {
            if (!is_string($row['id']) || !is_int($row['version']) || !is_string($row['operation_id'])) {
                throw new \UnexpectedValueException('Invalid owned checkout cancellation context.');
            }
            $eventId = $this->database->fetchOne('SELECT gen_random_uuid()::text');
            if (!is_string($eventId)) {
                throw new \RuntimeException('Unable to allocate cancellation identity.');
            }
            $this->database->executeStatement('UPDATE checkouts SET cancellation_requested_at=?,version=version+1 WHERE id=?', [$now->format(DATE_ATOM), $row['id']]);
            $this->outbox->append(new OutboxMessage($eventId, 'checkout:'.$row['id'].':cancellation-requested', 'checkout', $row['id'], $row['version'] + 1, 'checkout.cancellation_requested', 1, json_encode(['operation_id' => $row['operation_id']], JSON_THROW_ON_ERROR), $now));
        }
    }
}
