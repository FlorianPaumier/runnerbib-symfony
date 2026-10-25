<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Infrastructure\Identity\IdentityUserProvider;
use App\Tests\Support\IsolatedWebTestCase;

final class OrganizerImportHttpTest extends IsolatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach ([['00000000-0000-0000-0000-000000000001', 'organizer@example.test'], ['00000000-0000-0000-0000-000000000002', 'other@example.test']] as [$id, $email]) {
            $this->database->insert('accounts', ['id' => $id, 'terms_version' => 'fixture', 'terms_accepted_at' => '2026-10-12Z', 'verified_at' => '2026-10-12Z']);
            $this->database->insert('account_private_profiles', ['account_id' => $id, 'email' => $email, 'email_normalized' => $email]);
        }
        $this->database->insert('organizations', ['id' => '00000000-0000-0000-0000-000000000003', 'name' => 'Organisateur de Paris', 'approval_state' => 'approved', 'approved_by' => '00000000-0000-0000-0000-000000000002', 'approved_at' => '2026-10-12Z']);
        $this->database->insert('organization_memberships', ['id' => '00000000-0000-0000-0000-000000000004', 'organization_id' => '00000000-0000-0000-0000-000000000003', 'account_id' => '00000000-0000-0000-0000-000000000001', 'role' => 'owner', 'joined_at' => '2026-10-12Z']);
        $this->database->insert('races', ['id' => '00000000-0000-0000-0000-000000000005', 'organization_id' => '00000000-0000-0000-0000-000000000003', 'name' => 'Course de Paris', 'starts_at' => '2027-04-01Z', 'timezone' => 'Europe/Paris', 'sport_code' => 'running', 'distance_metres' => 10000, 'city' => 'Paris', 'country_code' => 'FR', 'created_at' => '2026-10-12Z', 'updated_at' => '2026-10-12Z', 'participation' => 'participating']);
    }

    public function testNestedOrNumericUpdateFieldsAreRejectedBeforeImportMutation(): void
    {
        $client = $this->configuredClient();
        $url = '/organisations/courses/00000000-0000-0000-0000-000000000005/import';
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $parameters = ['_token' => $crawler->filter('input[name="_token"]')->attr('value'), 'import_id' => $crawler->filter('input[name="import_id"]')->attr('value'), 'action' => 'execute', 'mode' => 'update'];
        foreach ([[['email']], [42], [null]] as $fields) {
            $client->request('POST', $url, array_replace($parameters, ['champs' => $fields]));
            self::assertResponseStatusCodeSame(400);
            self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM eligibility_imports'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM bib_entitlements'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        }
    }

    public function testPreviewDoesNotPersistThenFreshUploadCommitsImmutableResultsAndReplay(): void
    {
        $client = $this->configuredClient();
        $url = '/organisations/courses/00000000-0000-0000-0000-000000000005/import';
        $crawler = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $importId = $crawler->filter('input[name="import_id"]')->attr('value');
        self::assertIsString($token);
        self::assertIsString($importId);
        $csv = "entry,contact,holder\n00123,runner@example.test,Runner\n00456,invalid,Wrong\n00123,other@example.test,Duplicate";
        $source = tempnam(sys_get_temp_dir(), 'import-');
        self::assertIsString($source);
        file_put_contents($source, $csv);
        try {
            $file = new \Symfony\Component\HttpFoundation\File\UploadedFile($source, 'inscriptions.csv', 'text/csv', UPLOAD_ERR_OK, true);
            $client->request('POST', $url, ['_token' => $token, 'import_id' => $importId, 'action' => 'preview', 'mode' => 'skip_invalid'], ['csv' => $file]);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertSelectorTextContains('h2', 'Aperçu');
            self::assertSelectorTextContains('body', 'Valides : 1');
            self::assertSelectorTextContains('table', '00123');
            self::assertSelectorTextContains('body', 'Choisissez à nouveau le même fichier');
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM eligibility_imports'));
            self::assertStringNotContainsString('runner@example.test', (string) $client->getResponse()->getContent());
            self::assertSame($importId, $client->getCrawler()->filter('input[name="import_id"]')->attr('value'));
            file_put_contents($source, "wrong,headers\nnot,the-preview");
            $client->request('POST', $url, ['_token' => $token, 'import_id' => $importId, 'action' => 'execute', 'mode' => 'skip_invalid'], ['csv' => new \Symfony\Component\HttpFoundation\File\UploadedFile($source, 'inscriptions.csv', 'text/csv', UPLOAD_ERR_OK, true)]);
            self::assertSame(400, $client->getResponse()->getStatusCode());
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM eligibility_imports'));
            self::assertSame($importId, $client->getCrawler()->filter('input[name="import_id"]')->attr('value'));
            file_put_contents($source, $csv);
            foreach ([1, 2] as $attempt) {
                $file = new \Symfony\Component\HttpFoundation\File\UploadedFile($source, 'inscriptions.csv', 'text/csv', UPLOAD_ERR_OK, true);
                $client->request('POST', $url, ['_token' => $token, 'import_id' => $importId, 'action' => 'execute', 'mode' => 'skip_invalid'], ['csv' => $file]);
                self::assertSame(200, $client->getResponse()->getStatusCode());
                self::assertSelectorTextContains('h2', 'Résultat');
                self::assertSelectorTextContains('table', 'Importée');
                self::assertSelectorTextContains('body', 'Importées : 1');
                self::assertSelectorTextContains('body', 'Invalides : 1');
                self::assertSelectorTextContains('body', 'Doublons ignorés : 1');
                self::assertStringNotContainsString('runner@example.test', (string) $client->getResponse()->getContent());
                self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM eligibility_imports'));
                self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM bib_entitlements'));
                self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
            }
            self::assertSame('00123', $this->database->fetchOne('SELECT bib_number FROM bib_entitlements'));
            self::assertNull($this->database->fetchOne('SELECT current_owner_account_id FROM bib_entitlements'));
        } finally {
            unlink($source);
        }
    }

    public function testExplicitAllowedUpdatesPreserveOtherFieldsAndClaimedEntryIsProtected(): void
    {
        $client = $this->configuredClient();
        $url = '/organisations/courses/00000000-0000-0000-0000-000000000005/import';
        $source = tempnam(sys_get_temp_dir(), 'import-');
        self::assertIsString($source);
        try {
            foreach ([['skip_invalid', [], 'Original', 'other@example.test'], ['update', ['name'], 'Updated', 'must-not-replace@example.test']] as [$mode, $fields, $name, $email]) {
                $crawler = $client->request('GET', $url);
                file_put_contents($source, "entry,contact,holder\n00123,".$email.','.$name);
                $client->request('POST', $url, ['_token' => $crawler->filter('input[name="_token"]')->attr('value'), 'import_id' => $crawler->filter('input[name="import_id"]')->attr('value'), 'action' => 'execute', 'mode' => $mode, 'champs' => $fields], ['csv' => new \Symfony\Component\HttpFoundation\File\UploadedFile($source, 'inscriptions.csv', 'text/csv', UPLOAD_ERR_OK, true)]);
                self::assertSame(200, $client->getResponse()->getStatusCode());
            }
            self::assertSelectorTextContains('body', 'Mises à jour : 1');
            self::assertSame('Updated', $this->database->fetchOne('SELECT original_holder_name FROM bib_entitlements'));
            self::assertSame('other@example.test', $this->database->fetchOne('SELECT original_runner_email FROM bib_entitlements'));
            $entitlement = $this->database->fetchOne('SELECT id FROM bib_entitlements');
            self::assertIsString($entitlement);
            $proofs = new \App\Infrastructure\Inventory\DbalOwnershipProofStore($this->database, new \App\Infrastructure\Operations\DbalAuditWriter($this->database));
            self::assertTrue((new \App\Application\Inventory\SubmitOwnershipProof($proofs))->imported('00000000-0000-0000-0000-000000000002', $entitlement, '00000000-0000-0000-0000-000000000020', '00000000-0000-0000-0000-000000000021', new \DateTimeImmutable('2026-10-12Z')));
            self::assertTrue((new \App\Application\Inventory\ClaimEntitlement(new \App\Infrastructure\Inventory\DbalEntitlementStore($this->database)))->execute('00000000-0000-0000-0000-000000000002', $entitlement, '00000000-0000-0000-0000-000000000020', '00000000-0000-0000-0000-000000000022', new \DateTimeImmutable('2026-10-12Z')));
            $crawler = $client->request('GET', $url);
            file_put_contents($source, "entry,contact,holder\n00123,changed@example.test,Protected change");
            $parameters = ['_token' => $crawler->filter('input[name="_token"]')->attr('value'), 'import_id' => $crawler->filter('input[name="import_id"]')->attr('value'), 'action' => 'execute', 'mode' => 'update', 'champs' => ['name', 'email']];
            $client->request('POST', $url, $parameters, ['csv' => new \Symfony\Component\HttpFoundation\File\UploadedFile($source, 'inscriptions.csv', 'text/csv', UPLOAD_ERR_OK, true)]);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertSelectorTextContains('body', 'Protégées : 1');
            self::assertSame('Updated', $this->database->fetchOne('SELECT original_holder_name FROM bib_entitlements'));
            self::assertSame('other@example.test', $this->database->fetchOne('SELECT original_runner_email FROM bib_entitlements'));
            self::assertSame('00000000-0000-0000-0000-000000000002', $this->database->fetchOne('SELECT current_owner_account_id FROM bib_entitlements'));
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM ownership_transitions'));
            $parameters['import_id'] = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
            $parameters['champs'] = ['current_owner_account_id'];
            $client->request('POST', $url, $parameters, ['csv' => new \Symfony\Component\HttpFoundation\File\UploadedFile($source, 'inscriptions.csv', 'text/csv', UPLOAD_ERR_OK, true)]);
            self::assertSame(400, $client->getResponse()->getStatusCode());
            self::assertSame(3, $this->database->fetchOne('SELECT count(*) FROM eligibility_imports'));
        } finally {
            unlink($source);
        }
    }

    public function testUpdateUnavailableWithoutPolicyAndCommitRequiresParticipatingRace(): void
    {
        $client = $this->configuredClient('organizer@example.test', null);
        $url = '/organisations/courses/00000000-0000-0000-0000-000000000005/import';
        $crawler = $client->request('GET', $url);
        self::assertSelectorNotExists('option[value="update"]');
        $parameters = ['_token' => $crawler->filter('input[name="_token"]')->attr('value'), 'import_id' => $crawler->filter('input[name="import_id"]')->attr('value'), 'action' => 'execute', 'mode' => 'update', 'champs' => ['name']];
        $client->request('POST', $url, $parameters);
        self::assertSame(503, $client->getResponse()->getStatusCode());
        $source = tempnam(sys_get_temp_dir(), 'import-');
        self::assertIsString($source);
        file_put_contents($source, "entry,contact,holder\n00123,runner@example.test,Runner");
        try {
            $this->database->executeStatement("UPDATE races SET participation='nonparticipating'");
            $parameters['mode'] = 'skip_invalid';
            $parameters['champs'] = [];
            $client->request('POST', $url, $parameters, ['csv' => new \Symfony\Component\HttpFoundation\File\UploadedFile($source, 'inscriptions.csv', 'text/csv', UPLOAD_ERR_OK, true)]);
            self::assertSame(409, $client->getResponse()->getStatusCode());
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM eligibility_imports'));
        } finally {
            unlink($source);
        }
    }

    public function testEmptyApprovedUpdateListHidesUpdateAndRejectsForgedSelection(): void
    {
        $client = $this->configuredClient('organizer@example.test', []);
        $url = '/organisations/courses/00000000-0000-0000-0000-000000000005/import';
        $crawler = $client->request('GET', $url);
        self::assertSelectorNotExists('option[value="update"]');
        $source = tempnam(sys_get_temp_dir(), 'import-');
        self::assertIsString($source);
        file_put_contents($source, "entry,contact,holder\n00123,runner@example.test,Runner");
        try {
            $client->request('POST', $url, ['_token' => $crawler->filter('input[name="_token"]')->attr('value'), 'import_id' => $crawler->filter('input[name="import_id"]')->attr('value'), 'action' => 'execute', 'mode' => 'update', 'champs' => ['name']], ['csv' => new \Symfony\Component\HttpFoundation\File\UploadedFile($source, 'inscriptions.csv', 'text/csv', UPLOAD_ERR_OK, true)]);
            self::assertSame(400, $client->getResponse()->getStatusCode());
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM eligibility_imports'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM bib_entitlements'));
        } finally {
            unlink($source);
        }
    }

    public function testNonmemberDeniedBeforePolicyAndPreviewWithoutRaceOrSourceLeak(): void
    {
        $client = $this->configuredClient('other@example.test');
        $client->request('GET', '/organisations/courses/00000000-0000-0000-0000-000000000005/import');
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Course de Paris', (string) $client->getResponse()->getContent());
        $client->request('POST', '/organisations/courses/00000000-0000-0000-0000-000000000005/import', ['action' => 'preview']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM eligibility_imports'));
    }

    public function testAnonymousActorRedirectsAndUnverifiedOrUnapprovedOrganizationCannotPreview(): void
    {
        $client = $this->configuredClient(null);
        $url = '/organisations/courses/00000000-0000-0000-0000-000000000005/import';
        $client->request('GET', $url);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('organizer@example.test'));
        $this->database->executeStatement("UPDATE accounts SET verified_at=NULL WHERE id='00000000-0000-0000-0000-000000000001'");
        $client->request('GET', $url);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-12Z' WHERE id='00000000-0000-0000-0000-000000000001'");
        $this->database->executeStatement("UPDATE organizations SET approval_state='suspended'");
        $client->request('GET', $url);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM eligibility_imports'));
    }

    public function testInvalidCsrfIdentifierActionFieldsAndUploadErrorsNeverImport(): void
    {
        $client = $this->configuredClient();
        $url = '/organisations/courses/00000000-0000-0000-0000-000000000005/import';
        $crawler = $client->request('GET', $url);
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $id = $crawler->filter('input[name="import_id"]')->attr('value');
        self::assertIsString($token);
        self::assertIsString($id);
        $parameters = ['_token' => $token, 'import_id' => $id, 'action' => 'execute', 'mode' => 'skip_invalid'];
        $client->request('POST', $url, ['_token' => 'invalid'] + $parameters);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        foreach ([['import_id' => 'not-a-uuid'], ['action' => 'unrecognized'], ['mode' => 'unrecognized'], ['champs' => [1]], ['champs' => 'bad-shape'], ['action' => ['execute']], []] as $changes) {
            $client->request('POST', $url, $changes + $parameters);
            self::assertSame(400, $client->getResponse()->getStatusCode());
        }
        $source = tempnam(sys_get_temp_dir(), 'import-');
        self::assertIsString($source);
        try {
            file_put_contents($source, "entry,contact,holder\n00123,runner@example.test,Runner");
            foreach ([UPLOAD_ERR_PARTIAL, UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_CANT_WRITE] as $error) {
                $client->request('POST', $url, $parameters, ['csv' => new \Symfony\Component\HttpFoundation\File\UploadedFile($source, 'secret-filename.csv', 'text/csv', $error, true)]);
                self::assertSame(400, $client->getResponse()->getStatusCode());
                self::assertStringNotContainsString('secret-filename', (string) $client->getResponse()->getContent());
            }
            foreach ([str_repeat('x', 1025), "wrong,headers\nvalue,value", "entry,contact,holder\n00123,\xFF,Runner"] as $invalidCsv) {
                file_put_contents($source, $invalidCsv);
                $client->request('POST', $url, $parameters, ['csv' => new \Symfony\Component\HttpFoundation\File\UploadedFile($source, 'inscriptions.csv', 'text/csv', UPLOAD_ERR_OK, true)]);
                self::assertSame(400, $client->getResponse()->getStatusCode());
            }
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM eligibility_imports'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM bib_entitlements'));
            self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM administrative_audit'));
        } finally {
            unlink($source);
        }
    }

    /** @param list<string>|null $mutableFields */
    private function configuredClient(?string $email = 'organizer@example.test', ?array $mutableFields = ['name', 'email']): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = self::createClient();
        $client->disableReboot();
        $preview = new \App\Application\Imports\PreviewEligibilityImport(['bib' => 'entry', 'email' => 'contact', 'name' => 'holder'], 1024, 10);
        $imports = new \App\Application\Imports\ExecuteEligibilityImport($preview, new \App\Application\Organizations\OrganizationScope(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database)), new \App\Infrastructure\Imports\DbalEligibilityImportStore($this->database, new \App\Infrastructure\Operations\DbalAuditWriter($this->database)), new \App\Infrastructure\Operations\DbalTransactionManager($this->database), $mutableFields);
        $controller = new \App\Presentation\Http\OrganizerImportController(self::getContainer()->get(\App\Application\Identity\CurrentIdentity::class), new \App\Application\Organizations\OrganizationScope(new \App\Infrastructure\Organizations\DbalOrganizationStore($this->database)), $preview, $imports, 1024, $mutableFields);
        $controller->setContainer(self::getContainer());
        self::getContainer()->set(\App\Presentation\Http\OrganizerImportController::class, $controller);
        if (null !== $email) {
            $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier($email));
        }

        return $client;
    }

    public function testAuthorizedOrganizerReceivesFrenchPolicyUnavailableWithoutAnyImport(): void
    {
        $client = self::createClient();
        $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('organizer@example.test'));
        $client->request('GET', '/organisations/courses/00000000-0000-0000-0000-000000000005/import');
        self::assertSame(503, $client->getResponse()->getStatusCode());
        self::assertSelectorTextContains('h1', 'Importer les inscriptions');
        self::assertSelectorTextContains('[role="alert"]', 'configuration');
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));
        self::assertSame(0, $this->database->fetchOne('SELECT count(*) FROM eligibility_imports'));
    }
}
