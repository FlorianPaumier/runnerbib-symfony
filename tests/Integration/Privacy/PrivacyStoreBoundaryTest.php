<?php

declare(strict_types=1);

namespace App\Tests\Integration\Privacy;

use App\Application\Privacy\PrivacyProgressAuthority;
use App\Infrastructure\Privacy\DbalPrivacyRequestStore;
use App\Tests\Support\IsolatedWebTestCase;

final class PrivacyStoreBoundaryTest extends IsolatedWebTestCase
{
    private const OWNER = '00000000-0000-0000-0000-000000000001';
    private const REQUEST = '00000000-0000-0000-0000-000000000070';

    public function testIntakeAndFencingRequireAnOwningTransaction(): void
    {
        $this->seed();
        $store = new DbalPrivacyRequestStore($this->database);
        foreach (['lock', 'fence'] as $action) {
            try {
                if ('lock' === $action) {
                    $store->lock(self::OWNER, true);
                } else {
                    $store->fence(self::OWNER, new \DateTimeImmutable('2026-10-13Z'));
                }
                self::fail('Unowned privacy mutation succeeded.');
            } catch (\LogicException $failure) {
                self::assertStringContainsString('owning transaction', $failure->getMessage());
            }
        }
        self::assertSame(['lifecycle_state' => 'active', 'version' => 1], $this->database->fetchAssociative('SELECT lifecycle_state,version FROM accounts WHERE id=?', [self::OWNER]));
        self::assertSame('published', $this->database->fetchOne('SELECT state FROM resale_listings'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testMissingAndSuspendedOwnersCannotBeLockedForIntake(): void
    {
        $this->seed();
        $this->database->executeStatement("UPDATE accounts SET lifecycle_state='suspended' WHERE id=?", [self::OWNER]);
        foreach ([self::OWNER, self::REQUEST] as $owner) {
            try {
                $this->database->transactional(fn () => (new DbalPrivacyRequestStore($this->database))->lock($owner));
                self::fail('Unavailable account accepted privacy intake.');
            } catch (\DomainException $failure) {
                self::assertSame('Privacy request is unavailable.', $failure->getMessage());
            }
        }
        self::assertSame(1, $this->database->fetchOne('SELECT version FROM accounts WHERE id=?', [self::OWNER]));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
    }

    public function testStringifiedAccountAndListingFactsCannotAuthorizePrivacyWork(): void
    {
        $this->seed();
        $connection = $this->stringifiedDatabase();
        $store = new DbalPrivacyRequestStore($connection);
        try {
            foreach (['lock', 'unsoldListings'] as $action) {
                try {
                    $connection->transactional(fn () => 'lock' === $action ? $store->lock(self::OWNER) : $store->unsoldListings(self::OWNER));
                    self::fail('Stringified privacy fact was accepted.');
                } catch (\UnexpectedValueException $failure) {
                    self::assertSame('lock' === $action ? 'Invalid stored privacy account.' : 'Invalid stored privacy listing.', $failure->getMessage());
                }
                self::assertFalse($connection->isTransactionActive());
            }
        } finally {
            $connection->close();
        }
        self::assertSame(1, $this->database->fetchOne('SELECT version FROM accounts WHERE id=?', [self::OWNER]));
        self::assertSame('published', $this->database->fetchOne('SELECT state FROM resale_listings'));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
    }

    public function testStatusRequiresNativeCurrentAccountVersion(): void
    {
        $this->seed();
        $expires = new \DateTimeImmutable('2030-01-01Z');
        $metadata = json_encode(['kind' => 'access_export', 'state' => 'pending', 'account_version' => 1, 'authority_expires_at' => $expires->format(DATE_ATOM), 'request_version' => 1, 'blockers' => []], JSON_THROW_ON_ERROR);
        $this->database->insert('administrative_audit', ['id' => self::REQUEST, 'actor_account_id' => self::OWNER, 'authority_snapshot' => $metadata, 'action' => 'privacy.requested', 'subject_kind' => 'privacy_request', 'subject_id' => self::REQUEST, 'occurred_at' => '2026-10-13Z', 'outcome' => 'pending']);
        $connection = $this->stringifiedDatabase();
        try {
            try {
                (new DbalPrivacyRequestStore($connection))->current(new PrivacyProgressAuthority(self::REQUEST, self::OWNER, 1, $expires));
                self::fail('Stringified account version granted status access.');
            } catch (\UnexpectedValueException $failure) {
                self::assertSame('Invalid stored privacy account.', $failure->getMessage());
                self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
                self::assertSame('active', $this->database->fetchOne('SELECT lifecycle_state FROM accounts WHERE id=?', [self::OWNER]));
            }
        } finally {
            $connection->close();
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOrigins')]
    public function testIncompleteStoredOriginCannotGrantProgress(bool $hasOwner, string $subject, string $message): void
    {
        $this->seed();
        $this->database->insert('administrative_audit', ['id' => self::REQUEST, 'actor_account_id' => $hasOwner ? self::OWNER : null, 'service_principal' => $hasOwner ? null : 'fixture-service', 'authority_snapshot' => '{}', 'action' => 'privacy.requested', 'subject_kind' => $subject, 'subject_id' => self::REQUEST, 'occurred_at' => '2026-10-13Z', 'outcome' => 'pending']);
        try {
            (new DbalPrivacyRequestStore($this->database))->existing(self::REQUEST);
            self::fail('Incomplete origin granted progress.');
        } catch (\UnexpectedValueException $failure) {
            self::assertSame($message, $failure->getMessage());
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM outbox_messages'));
        }
    }

    /** @return iterable<string,array{bool,string,string}> */
    public static function invalidOrigins(): iterable
    {
        yield 'service origin' => [false, 'privacy_request', 'Invalid stored privacy owner.'];
        yield 'wrong subject' => [true, 'account', 'Invalid stored privacy progress.'];
    }

    private function seed(): void
    {
        $fixture = file_get_contents(dirname(__DIR__, 2).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement($fixture);
    }
}
