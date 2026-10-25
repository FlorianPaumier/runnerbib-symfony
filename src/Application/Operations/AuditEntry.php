<?php

declare(strict_types=1);

namespace App\Application\Operations;

final readonly class AuditEntry
{
    public function __construct(
        public string $id,
        public ?string $actorAccountId,
        public ?string $servicePrincipal,
        public string $authoritySnapshot,
        public string $action,
        public string $subjectKind,
        public string $subjectId,
        public ?string $reason,
        public \DateTimeImmutable $occurredAt,
        public string $outcome,
    ) {
    }
}
