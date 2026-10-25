<?php

declare(strict_types=1);

namespace App\Tests\Imports;

use App\Tests\Support\IsolatedWebTestCase;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\Process\Process;

final class ImportResultsMigrationTest extends IsolatedWebTestCase
{
    public function testCompletedImportResultsCannotBeRewrittenOrDeleted(): void
    {
        $this->seedCompletedImport();
        foreach (["UPDATE eligibility_imports SET row_results='[]'", 'DELETE FROM eligibility_imports'] as $sql) {
            try {
                $this->database->executeStatement($sql);
                self::fail('Completed import results must remain immutable.');
            } catch (DriverException) {
                self::assertSame(1, $this->database->fetchOne('SELECT count(*) FROM eligibility_imports'));
            }
        }
    }

    public function testRollbackAndReplayRestoreCompletedImportProtection(): void
    {
        $this->seedCompletedImport();
        $url = $_SERVER['DATABASE_URL'];
        self::assertIsString($url);
        $command = ['php', 'bin/console', 'doctrine:migrations:execute', 'DoctrineMigrations\\Version20261010000100', '--no-interaction'];
        (new Process([...$command, '--down'], dirname(__DIR__, 2), ['DATABASE_URL' => $url, 'APP_ENV' => 'test']))->setTimeout(120)->mustRun();
        self::assertSame(1, $this->database->executeStatement("UPDATE eligibility_imports SET row_results='[]'"));
        (new Process([...$command, '--up'], dirname(__DIR__, 2), ['DATABASE_URL' => $url, 'APP_ENV' => 'test']))->setTimeout(120)->mustRun();
        try {
            $this->database->executeStatement("UPDATE eligibility_imports SET state='processing'");
            self::fail('Replay must protect original import state.');
        } catch (DriverException) {
            self::assertSame('completed', $this->database->fetchOne('SELECT state FROM eligibility_imports'));
        }
        self::assertSame(1, $this->database->executeStatement('UPDATE eligibility_imports SET state=state'));
    }

    private function seedCompletedImport(): void
    {
        $this->database->executeStatement("INSERT INTO accounts(id,terms_version,terms_accepted_at) VALUES ('00000000-0000-0000-0000-000000000001','v1','2026-01-01Z')");
        $this->database->executeStatement("INSERT INTO races(id,name,starts_at,timezone,sport_code,distance_metres,city,country_code,created_at,updated_at) VALUES ('00000000-0000-0000-0000-000000000002','Race','2027-01-01Z','Europe/Paris','running',10000,'Paris','FR','2026-01-01Z','2026-01-01Z')");
        $this->database->executeStatement("INSERT INTO eligibility_imports(id,race_id,actor_id,mode,state,started_at,completed_at,row_results) VALUES ('00000000-0000-0000-0000-000000000003','00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-000000000001','skip_invalid','completed','2026-01-01Z','2026-01-01Z','[{\"row\":2,\"status\":\"imported\",\"bib\":\"00123\"}]')");
    }
}
