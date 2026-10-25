<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Infrastructure\Identity\IdentityUserProvider;
use App\Tests\Support\IsolatedWebTestCase;

final class OrganizationPagesTest extends IsolatedWebTestCase
{
    public function testOrganizationDashboardIsScopedToAuthenticatedMembership(): void
    {
        $id = '00000000-0000-0000-0000-000000000001';
        $organizationId = '00000000-0000-0000-0000-000000000010';
        $this->database->insert('accounts', ['id' => $id, 'terms_version' => 'fixture', 'terms_accepted_at' => '2026-10-08Z', 'verified_at' => '2026-10-08Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $id, 'email' => 'owner@example.test', 'email_normalized' => 'owner@example.test']);
        $this->database->insert('organizations', ['id' => $organizationId, 'name' => 'Les coureurs de Paris']);
        $this->database->insert('organizations', ['id' => '00000000-0000-0000-0000-000000000011', 'name' => 'Organisation privée']);
        $this->database->insert('organization_memberships', ['id' => '00000000-0000-0000-0000-000000000012', 'organization_id' => $organizationId, 'account_id' => $id, 'role' => 'owner', 'joined_at' => '2026-10-08Z']);
        $client = self::createClient();
        $client->request('GET', '/organisations/'.$organizationId);
        self::assertResponseRedirects('/connexion');
        $user = self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('owner@example.test');
        $client->loginUser($user);
        $client->request('GET', '/organisations/'.$organizationId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Les coureurs de Paris');
        self::assertSelectorTextContains('main', 'En attente');
        $client->request('GET', '/organisations');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/organisations/00000000-0000-0000-0000-000000000010"]');
        $client->request('GET', '/organisations/00000000-0000-0000-0000-000000000011');
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextNotContains('main', 'Organisation privée');
    }

    public function testOrganizationLandingHasAnonymousEmptyAndUnverifiedStates(): void
    {
        $id = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $id, 'terms_version' => 'fixture', 'terms_accepted_at' => '2026-10-08Z', 'verified_at' => '2026-10-08Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $id, 'email' => 'runner@example.test', 'email_normalized' => 'runner@example.test']);
        $client = self::createClient();
        $client->request('GET', '/organisations');
        self::assertResponseRedirects('/connexion');
        $user = self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('runner@example.test');
        $client->loginUser($user);
        $client->request('GET', '/organisations');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', 'aucune organisation');
        $this->database->update('accounts', ['verified_at' => null], ['id' => $id]);
        $client->request('GET', '/organisations');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/organisations/demande');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM organizations'));
    }

    public function testVerifiedRunnerCanRequestAnOrganizationWithoutApprovingIt(): void
    {
        $id = '00000000-0000-0000-0000-000000000001';
        $this->database->insert('accounts', ['id' => $id, 'terms_version' => 'fixture', 'terms_accepted_at' => '2026-10-08Z', 'verified_at' => '2026-10-08Z']);
        $this->database->insert('account_private_profiles', ['account_id' => $id, 'email' => 'runner@example.test', 'email_normalized' => 'runner@example.test']);
        $client = self::createClient();
        $client->request('GET', '/organisations/demande');
        self::assertResponseRedirects('/connexion');
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('runner@example.test'));
        $crawler = $client->request('GET', '/organisations/demande');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/organisations/demande', ['nom' => 'Club des coureurs', '_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $client->submit($crawler->selectButton('Demander un accès organisateur')->form(['nom' => '   ']));
        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM organizations'));
        $client->submit($crawler->selectButton('Demander un accès organisateur')->form(['nom' => 'Club des coureurs']));
        self::assertResponseRedirects('/organisations');
        self::assertSame('pending', $this->database->fetchOne('SELECT approval_state FROM organizations'));
        self::assertSame($id, $this->database->fetchOne("SELECT account_id::text FROM organization_memberships WHERE role='owner'"));
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='organization.requested'"));
        $client->followRedirect();
        self::assertSelectorTextContains('main', 'Club des coureurs');
        $client->submit($crawler->selectButton('Demander un accès organisateur')->form(['nom' => 'Deuxième club']));
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM organizations'));
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM administrative_audit WHERE action='organization.requested'"));
    }
}
