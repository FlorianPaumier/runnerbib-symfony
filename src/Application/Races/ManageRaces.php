<?php

declare(strict_types=1);

namespace App\Application\Races;

use App\Application\Operations\AuditEntry;
use App\Application\Operations\AuditWriter;
use App\Application\Operations\TransactionManager;
use App\Application\Organizations\OrganizationStore;
use App\Domain\Organizations\OrganizationPolicy;
use App\Domain\Races\RaceId;

final readonly class ManageRaces
{
    public function __construct(private RaceRepository $races, private OrganizationStore $organizations, private TransactionManager $transactions, private AuditWriter $audit)
    {
    }

    public function create(string $actorAccountId, RaceDefinition $definition, string $auditId, \DateTimeImmutable $now): void
    {
        $this->transactions->run(function () use ($actorAccountId, $definition, $auditId, $now): void {
            $authority = $this->authorize($actorAccountId, $definition->organizationId);
            $this->races->save($definition->toRace($now, $now));
            $this->audit->append(new AuditEntry($auditId, $actorAccountId, null, $authority, 'race.created', 'race', $definition->id, null, $now, 'succeeded'));
        });
    }

    public function update(string $actorAccountId, RaceDefinition $definition, int $expectedVersion, string $auditId, \DateTimeImmutable $now): void
    {
        $this->transactions->run(function () use ($actorAccountId, $definition, $expectedVersion, $auditId, $now): void {
            $previous = $this->races->get(new RaceId($definition->id));
            if (null === $previous) {
                throw new \DomainException('Race does not exist.');
            }
            $authority = $this->authorize($actorAccountId, $previous->organizationId?->value);
            $replacement = $definition->toRace($previous->createdAt, $now, $previous->version);
            $previous->assertSameOrganization($replacement);
            $previous->assertSameParticipation($replacement);
            $this->races->save($replacement, $expectedVersion);
            $this->audit->append(new AuditEntry($auditId, $actorAccountId, null, $authority, 'race.updated', 'race', $definition->id, null, $now, 'succeeded'));
        });
    }

    public function archive(string $actorAccountId, string $raceId, int $expectedVersion, string $auditId, \DateTimeImmutable $now): void
    {
        $this->transactions->run(function () use ($actorAccountId, $raceId, $expectedVersion, $auditId, $now): void {
            $previous = $this->races->get(new RaceId($raceId));
            if (null === $previous) {
                throw new \DomainException('Race does not exist.');
            }
            $authority = $this->authorize($actorAccountId, $previous->organizationId?->value);
            $this->races->save($previous->archive($now), $expectedVersion);
            $this->audit->append(new AuditEntry($auditId, $actorAccountId, null, $authority, 'race.archived', 'race', $raceId, null, $now, 'succeeded'));
        });
    }

    private function authorize(string $actorAccountId, ?string $organizationId): string
    {
        $actor = $this->organizations->actor($actorAccountId);
        if (null === $organizationId) {
            OrganizationPolicy::requireAdministrator($actor);

            return json_encode(['roles' => $actor?->roles, 'organization_id' => null], JSON_THROW_ON_ERROR);
        }
        $this->organizations->lockOrganization($organizationId);
        OrganizationPolicy::requireMember($actor, $this->organizations->membershipRole($organizationId, $actorAccountId));
        OrganizationPolicy::requireApproved($this->organizations->get($organizationId));

        return json_encode(['roles' => $actor?->roles, 'organization_id' => $organizationId, 'membership' => $this->organizations->membershipRole($organizationId, $actorAccountId)], JSON_THROW_ON_ERROR);
    }
}
