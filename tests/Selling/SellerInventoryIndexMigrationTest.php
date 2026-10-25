<?php

declare(strict_types=1);

namespace App\Tests\Selling;

use App\Tests\Support\IsolatedWebTestCase;
use Symfony\Component\Process\Process;

final class SellerInventoryIndexMigrationTest extends IsolatedWebTestCase
{
    public function testSellerHistoryIndexApplyRollbackReplayAndActualInventoryPlan(): void
    {
        $fixture = file_get_contents(dirname(__DIR__).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement($fixture);
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-11Z'");
        $this->database->executeStatement("INSERT INTO bib_entitlements(id,race_id,bib_number,source_kind) SELECT gen_random_uuid(),'00000000-0000-0000-0000-000000000005','unclaimed-'||n,'document' FROM generate_series(1,5000) n");
        $this->database->executeStatement("INSERT INTO resale_listings(id,race_id,entitlement_id,seller_id,price_cents) SELECT gen_random_uuid(),'00000000-0000-0000-0000-000000000004','00000000-0000-0000-0000-000000000006','00000000-0000-0000-0000-000000000001',10000 FROM generate_series(1,5000)");
        $this->database->executeStatement("INSERT INTO bib_entitlements(id,race_id,bib_number,source_kind,current_owner_account_id) VALUES('00000000-0000-0000-0000-000000000030','00000000-0000-0000-0000-000000000005','00007','document','00000000-0000-0000-0000-000000000002')");
        $this->database->executeStatement("INSERT INTO resale_listings(id,race_id,entitlement_id,seller_id,price_cents) VALUES('00000000-0000-0000-0000-000000000031','00000000-0000-0000-0000-000000000005','00000000-0000-0000-0000-000000000030','00000000-0000-0000-0000-000000000002',10000)");
        foreach (['accounts', 'bib_entitlements', 'resale_listings'] as $table) {
            $this->database->executeStatement('ANALYZE '.$table);
        }
        $logger = new class extends \Psr\Log\AbstractLogger {
            public ?string $sql = null;

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (isset($context['sql']) && is_string($context['sql'])) {
                    $this->sql = $context['sql'];
                }
            }
        };
        $configuration = new \Doctrine\DBAL\Configuration();
        $configuration->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($logger)]);
        $connection = \Doctrine\DBAL\DriverManager::getConnection($this->database->getParams(), $configuration);
        try {
            $tokens = new \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
            $tokens->setToken(new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(new \App\Infrastructure\Identity\SecurityUser('00000000-0000-0000-0000-000000000002', 'seller@example.test', 'hash', ['runner'], 1), 'main', ['ROLE_USER']));
            $inventory = new \App\Application\Selling\SellerInventory(new \App\Infrastructure\Identity\SecurityCurrentIdentity($tokens), new \App\Infrastructure\Selling\DbalSellerInventoryStore($connection));
            self::assertCount(1, $inventory->list());
            $sql = $logger->sql;
            self::assertIsString($sql);
            $plan = $this->database->fetchOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, ['00000000-0000-0000-0000-000000000002']);
            self::assertIsString($plan);
            file_put_contents(dirname(__DIR__, 2).'/var/inventory-index-plan.json', $plan);
            self::assertSame(1, $this->database->fetchOne("SELECT count(*) FROM pg_indexes WHERE indexname='ix_listing_seller_entitlement'"));
            self::assertStringContainsString('ix_listing_seller_entitlement', $plan);
            self::assertStringContainsString('uq_entitlement_owner_race', $plan);
            $this->migration('down')->mustRun();
            self::assertSame(0, $this->database->fetchOne("SELECT count(*) FROM pg_indexes WHERE indexname='ix_listing_seller_entitlement'"));
            self::assertCount(1, $inventory->list());
            $withoutIndex = $this->database->fetchOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, ['00000000-0000-0000-0000-000000000002']);
            self::assertIsString($withoutIndex);
            self::assertStringContainsString('Seq Scan', $withoutIndex);
            $this->migration('up')->mustRun();
            self::assertSame(5002, $this->database->fetchOne('SELECT count(*) FROM resale_listings'));
            self::assertCount(1, $inventory->list());
        } finally {
            $connection->close();
        }
    }

    private function migration(string $direction): Process
    {
        $url = $_SERVER['DATABASE_URL'];
        self::assertIsString($url);

        return (new Process(['php', 'bin/console', 'doctrine:migrations:execute', 'DoctrineMigrations\\Version20261011000100', '--'.$direction, '--no-interaction'], dirname(__DIR__, 2), ['DATABASE_URL' => $url, 'APP_ENV' => 'test']))->setTimeout(120);
    }
}
