<?php

declare(strict_types=1);

namespace App\Domain\Imports;

final readonly class ImportMode
{
    /**
     * @param array<array-key,string>      $selectedFields
     * @param array<array-key,string>|null $approvedFields
     */
    public function __construct(public string $value, public array $selectedFields, ?array $approvedFields)
    {
        if (!in_array($value, ['skip_invalid', 'update'], true) || !array_is_list($selectedFields) || count(array_unique($selectedFields)) !== count($selectedFields)) {
            throw new \InvalidArgumentException('Invalid import mode.');
        }
        if ('skip_invalid' === $value) {
            if ([] !== $selectedFields) {
                throw new \InvalidArgumentException('Default imports do not update existing fields.');
            }

            return;
        }
        if (null === $approvedFields || !array_is_list($approvedFields) || array_diff($approvedFields, ['name', 'email'])) {
            throw new \LogicException('Import update policy is not configured.');
        }
        if ([] === $selectedFields || array_diff($selectedFields, $approvedFields)) {
            throw new \InvalidArgumentException('Import update fields are not approved.');
        }
    }
}
