<?php

declare(strict_types=1);

namespace App\Tests\Selling;

use App\Tests\Support\IsolatedWebTestCase;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\Process\Process;

final class DraftWithdrawalMigrationTest extends IsolatedWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $fixture = file_get_contents(dirname(__DIR__).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement($fixture);
    }

    private function migration(string $direction): Process
    {
        $url = $_SERVER['DATABASE_URL'];
        self::assertIsString($url);

        return (new Process(['php', 'bin/console', 'doctrine:migrations:execute', 'DoctrineMigrations\\Version20261010000200', '--'.$direction, '--no-interaction'], dirname(__DIR__, 2), ['DATABASE_URL' => $url, 'APP_ENV' => 'test']))->setTimeout(120);
    }

    public function testRollbackAndReplayPreservePublishedAndSoldProofRequirements(): void
    {
        $this->migration('down')->mustRun();
        $this->migration('up')->mustRun();
        foreach (['published', 'sold'] as $state) {
            try {
                $this->database->executeStatement("INSERT INTO resale_listings(id,race_id,seller_id,price_cents,state,published_at,sold_at) VALUES(gen_random_uuid(),'00000000-0000-0000-0000-000000000004','00000000-0000-0000-0000-000000000001',10000,?,'2026-01-01Z','2026-01-01Z')", [$state]);
                self::fail('Published and sold listings must retain verified proof requirements.');
            } catch (DriverException) {
                self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM resale_listings'));
            }
        }
        try {
            $this->database->executeStatement("INSERT INTO resale_listings(id,race_id,seller_id,price_cents,state) VALUES(gen_random_uuid(),'00000000-0000-0000-0000-000000000004','00000000-0000-0000-0000-000000000001',10000,'withdrawn')");
            self::fail('Withdrawal still requires its timestamp.');
        } catch (DriverException) {
            self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM resale_listings'));
        }
    }

    public function testUnsafeRollbackRefusesAndPreservesWithdrawnDraftAndMigrationMetadata(): void
    {
        $this->database->executeStatement("INSERT INTO resale_listings(id,race_id,seller_id,price_cents,state,withdrawn_at) VALUES('00000000-0000-0000-0000-000000000022','00000000-0000-0000-0000-000000000004','00000000-0000-0000-0000-000000000001',10000,'withdrawn','2026-01-01Z')");
        self::assertNotSame(0, $this->migration('down')->run());
        self::assertSame('withdrawn', $this->database->fetchOne("SELECT state FROM resale_listings WHERE id='00000000-0000-0000-0000-000000000022'"));
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM doctrine_migration_versions WHERE version='DoctrineMigrations\\Version20261010000200'"));
        self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM pg_constraint WHERE conname='ck_listing_published_proof'"));
    }
}
