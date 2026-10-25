<?php

declare(strict_types=1);

namespace App\Tests\Integration\Availability;

use App\Application\Availability\CaptureAvailability;
use App\Infrastructure\Availability\DbalAvailabilitySnapshotStore;
use App\Infrastructure\Operations\DbalOutboxWriter;
use App\Tests\Support\IsolatedWebTestCase;

abstract class AvailabilityTestCase extends IsolatedWebTestCase
{
    protected const MEMBER = '00000000-0000-0000-0000-000000000002';
    protected const LATE = '00000000-0000-0000-0000-000000000016';
    protected const RACE = '00000000-0000-0000-0000-000000000004';
    protected const LISTING = '00000000-0000-0000-0000-000000000009';
    protected const EVENT = '00000000-0000-0000-0000-000000000020';

    protected function publisher(): CaptureAvailability
    {
        return new CaptureAvailability(new DbalAvailabilitySnapshotStore($this->database), new DbalOutboxWriter($this->database));
    }

    protected function seed(): void
    {
        $fixture = file_get_contents(dirname(__DIR__, 2).'/Fixtures/marketplace.sql');
        self::assertIsString($fixture);
        $this->database->executeStatement(explode('INSERT INTO availability_events', $fixture)[0]);
        $this->database->executeStatement("UPDATE accounts SET verified_at='2026-10-10Z'");
        $this->database->executeStatement("UPDATE races SET resale_deadline='2027-03-31Z'");
        $this->database->executeStatement("INSERT INTO accounts(id,terms_version,terms_accepted_at,verified_at) VALUES (?,'v1','2026-10-10Z','2026-10-10Z')", [self::LATE]);
    }
}
