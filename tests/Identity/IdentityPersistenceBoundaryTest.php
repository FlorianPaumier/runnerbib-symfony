<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Infrastructure\Identity\DbalAccountLifecycleStore;
use App\Infrastructure\Identity\DbalIdentityStore;
use App\Infrastructure\Identity\DbalProfileStore;
use App\Infrastructure\Operations\DbalAuditWriter;
use App\Tests\Support\IsolatedWebTestCase;

final class IdentityPersistenceBoundaryTest extends IsolatedWebTestCase
{
    private const OWNER = '00000000-0000-0000-0000-000000000001';

    public function testStoredNonStringRoleCannotAuthenticateOrGrantAdministrativeAuthority(): void
    {
        $store = new DbalIdentityStore($this->database);
        $store->register(self::OWNER, 'role@example.test', 'fixture-hash', 'v1', new \DateTimeImmutable('2026-10-13Z'));
        $this->database->executeStatement("UPDATE accounts SET roles='[\"admin\",42]'::jsonb");
        try {
            $store->findActive('role@example.test');
            self::fail('Malformed role granted an authenticated identity.');
        } catch (\UnexpectedValueException $failure) {
            self::assertSame('Invalid stored account role.', $failure->getMessage());
        }
        self::assertFalse((new DbalAccountLifecycleStore($this->database, new DbalAuditWriter($this->database)))->change(self::OWNER, self::OWNER, new \App\Domain\Identity\AccountLifecycle('suspended', 'fixture reason'), '00000000-0000-0000-0000-000000000070', new \DateTimeImmutable('2026-10-13Z')));
        self::assertSame(['lifecycle_state' => 'active', 'version' => 1], $this->database->fetchAssociative('SELECT lifecycle_state,version FROM accounts'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testStringifiedPreferencesCannotBecomePrivateProfileBooleans(): void
    {
        (new DbalIdentityStore($this->database))->register(self::OWNER, 'private@example.test', 'fixture-hash', 'v1', new \DateTimeImmutable('2026-10-13Z'));
        $connection = $this->stringifiedDatabase();
        try {
            try {
                (new DbalProfileStore($connection))->privateProfile(self::OWNER);
                self::fail('String preferences became profile permissions.');
            } catch (\UnexpectedValueException $failure) {
                self::assertSame('Invalid stored private profile.', $failure->getMessage());
                self::assertSame(['optional_email_enabled' => false, 'optional_in_app_enabled' => false, 'version' => 1], $this->database->fetchAssociative('SELECT optional_email_enabled,optional_in_app_enabled,version FROM accounts'));
            }
        } finally {
            $connection->close();
        }
    }
}
