<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Application\Inventory\PrivateProofReader;
use App\Application\Inventory\UploadDocumentProof;
use App\Infrastructure\Identity\IdentityUserProvider;
use App\Infrastructure\Inventory\DbalOwnershipProofStore;
use App\Infrastructure\Inventory\LocalProofQuarantine;
use App\Infrastructure\Operations\DbalAuditWriter;
use App\Tests\Support\IsolatedWebTestCase;

final class ProofDownloadHttpTest extends IsolatedWebTestCase
{
    public function testOnlyAuthorizedReviewerReceivesPrivateAttachment(): void
    {
        $admin = '00000000-0000-0000-0000-000000000001';
        $runner = '00000000-0000-0000-0000-000000000002';
        $race = '00000000-0000-0000-0000-000000000010';
        $proof = '00000000-0000-0000-0000-000000000020';
        $object = '00000000-0000-0000-0000-000000000021';
        foreach ([[$admin, '["admin"]', 'admin@example.test'], [$runner, '["runner"]', 'runner@example.test']] as [$id, $roles, $email]) {
            $this->database->insert('accounts', ['id' => $id, 'roles' => $roles, 'terms_version' => 'fixture', 'terms_accepted_at' => '2026-10-11Z', 'verified_at' => '2026-10-11Z']);
            $this->database->insert('account_private_profiles', ['account_id' => $id, 'email' => $email, 'email_normalized' => $email]);
        }
        $this->database->insert('races', ['id' => $race, 'name' => 'Course de Paris', 'starts_at' => '2027-04-04Z', 'timezone' => 'Europe/Paris', 'sport_code' => 'running', 'distance_metres' => 10000, 'city' => 'Paris', 'country_code' => 'FR', 'participation' => 'nonparticipating', 'publication_state' => 'published', 'created_at' => '2026-10-11Z', 'updated_at' => '2026-10-11Z']);
        $directory = sys_get_temp_dir().'/http-proof-'.bin2hex(random_bytes(8));
        $source = tempnam(sys_get_temp_dir(), 'proof-');
        self::assertIsString($source);
        $contents = "%PDF-1.4\n%%EOF\n";
        file_put_contents($source, $contents);
        $storage = new LocalProofQuarantine($directory, ['application/pdf'], 100);
        try {
            self::assertTrue((new UploadDocumentProof(new DbalOwnershipProofStore($this->database, new DbalAuditWriter($this->database)), $storage))->execute($runner, $race, '00123', $source, 'application/pdf', $proof, $object, '00000000-0000-0000-0000-000000000022', new \DateTimeImmutable('2026-10-11Z')));
            $client = self::createClient();
            $client->disableReboot();
            self::getContainer()->set(PrivateProofReader::class, $storage);
            $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('admin@example.test'));
            $client->request('GET', '/admin/preuves/'.$proof.'/document');
            self::assertResponseIsSuccessful();
            self::assertSame($contents, $client->getResponse()->getContent());
            self::assertSame('application/octet-stream', $client->getResponse()->headers->get('Content-Type'));
            self::assertSame('attachment; filename='.$proof.'.blob', $client->getResponse()->headers->get('Content-Disposition'));
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
            self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));
            self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
            file_put_contents($directory.'/'.$object.'.blob', 'tampered private content');
            $client->request('GET', '/admin/preuves/'.$proof.'/document');
            self::assertResponseStatusCodeSame(503);
            self::assertSame('Document temporairement indisponible.', $client->getResponse()->getContent());
            file_put_contents($directory.'/'.$object.'.blob', $contents);
            $this->database->update('accounts', ['verified_at' => null], ['id' => $admin]);
            $client->request('GET', '/admin/preuves/'.$proof.'/document');
            self::assertResponseStatusCodeSame(404);
            $this->database->update('accounts', ['verified_at' => '2026-10-11Z'], ['id' => $admin]);
            $this->database->update('private_objects', ['purged_at' => '2026-10-11Z'], ['id' => $object]);
            $client->request('GET', '/admin/preuves/'.$proof.'/document');
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString($contents, (string) $client->getResponse()->getContent());
            self::assertNull($this->database->fetchOne('SELECT decision FROM ownership_proofs'));
            $client->loginUser(self::getContainer()->get(IdentityUserProvider::class)->loadUserByIdentifier('runner@example.test'));
            $client->request('GET', '/admin/preuves/'.$proof.'/document');
            self::assertResponseStatusCodeSame(403);
        } finally {
            unlink($source);
            if (is_file($directory.'/'.$object.'.blob')) {
                unlink($directory.'/'.$object.'.blob');
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
