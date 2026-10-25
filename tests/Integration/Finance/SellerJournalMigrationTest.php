<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Tests\Support\IsolatedWebTestCase;
use Doctrine\DBAL\Exception\DriverException;

final class SellerJournalMigrationTest extends IsolatedWebTestCase
{
    public function testAccountCreationIntentCannotBeChangedAfterPreparation(): void
    {
        $this->seed();
        try {
            $this->database->executeStatement("UPDATE seller_onboarding_operations SET parameters='{\"country\":\"US\"}'");
            self::fail('Prepared recipient identity was changed.');
        } catch (DriverException $exception) {
            self::assertSame('23514', $exception->getSQLState());
        }
    }

    public function testPrivateIdentityAllowsOneWayRedactionButNoReplacement(): void
    {
        $this->seed();
        $this->database->executeStatement("UPDATE seller_onboarding_operations SET private_payload=NULL, private_redacted_at='2026-10-09Z'");
        self::assertNull($this->database->fetchOne('SELECT private_payload FROM seller_onboarding_operations'));
        self::assertSame('seller-creation-2', $this->database->fetchOne('SELECT idempotency_key FROM seller_onboarding_operations'));
        try {
            $this->database->executeStatement("UPDATE seller_onboarding_operations SET private_payload='{\"email\":\"restored@example.test\"}', private_redacted_at=NULL");
            self::fail('Redacted provider identity was restored.');
        } catch (DriverException $exception) {
            self::assertSame('23514', $exception->getSQLState());
        }
    }

    private function seed(): void
    {
        $seller = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $seller, 'roles' => '["runner"]', 'terms_version' => 'v1', 'terms_accepted_at' => '2026-10-09Z']);
        $this->database->insert('seller_onboarding_operations', ['id' => '00000000-0000-0000-0000-000000000002', 'seller_id' => $seller, 'provider' => 'stripe', 'provider_scope' => 'test-platform', 'idempotency_key' => 'seller-creation-2', 'parameters' => '{"country":"DE"}', 'parameters_hash' => str_repeat('a', 64), 'private_payload' => '{"email":"runner@example.test"}', 'state' => 'prepared', 'created_at' => '2026-10-09Z']);
    }
}
