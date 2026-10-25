<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Infrastructure\Identity\DbalIdentityStore;
use App\Infrastructure\Identity\SecurityUser;
use App\Tests\Support\IsolatedWebTestCase;

final class AccountAdministrationHttpTest extends IsolatedWebTestCase
{
    public function testRunnerCannotManageAccountsAndAdminActionRequiresCsrfReasonAndAudit(): void
    {
        $now = new \DateTimeImmutable('2026-10-09Z');
        $hash = password_hash('correct horse battery staple', PASSWORD_ARGON2ID);
        $identities = new DbalIdentityStore($this->database);
        $identities->register('00000000-0000-0000-0000-000000000001', 'admin@example.test', $hash, 'v1', $now);
        $identities->register('00000000-0000-0000-0000-000000000002', 'runner@example.test', $hash, 'v1', $now);
        $this->database->executeStatement("UPDATE accounts SET roles='[\"admin\"]' WHERE id='00000000-0000-0000-0000-000000000001'");
        $client = self::createClient();
        $client->loginUser(new SecurityUser('00000000-0000-0000-0000-000000000002', 'runner@example.test', $hash, ['runner'], 1));
        $client->request('GET', '/admin/comptes/00000000-0000-0000-0000-000000000002/statut');
        self::assertResponseStatusCodeSame(403);
        $client->loginUser(new SecurityUser('00000000-0000-0000-0000-000000000001', 'admin@example.test', $hash, ['admin'], 1));
        $crawler = $client->request('GET', '/admin/comptes/00000000-0000-0000-0000-000000000002/statut');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Gestion du compte');
        $client->request('POST', '/admin/comptes/00000000-0000-0000-0000-000000000002/statut', ['state' => 'suspended', 'reason' => 'Review', '_csrf_token' => 'invalid']);
        self::assertResponseStatusCodeSame(400);
        $crawler = $client->request('GET', '/admin/comptes/00000000-0000-0000-0000-000000000002/statut');
        $client->submit($crawler->selectButton('Appliquer')->form(['state' => 'suspended', 'reason' => '   ']));
        self::assertResponseStatusCodeSame(400);
        $crawler = $client->request('GET', '/admin/comptes/00000000-0000-0000-0000-000000000002/statut');
        $client->submit($crawler->selectButton('Appliquer')->form(['state' => 'suspended', 'reason' => 'Safety investigation']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', 'État du compte modifié');
        self::assertNull($identities->findActive('runner@example.test'));
        self::assertSame('account.suspended', $this->database->fetchOne('SELECT action FROM administrative_audit'));
        self::assertSame('Safety investigation', $this->database->fetchOne('SELECT reason FROM administrative_audit'));
    }
}
