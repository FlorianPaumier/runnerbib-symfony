<?php

declare(strict_types=1);

namespace App\Domain\Registration;

final readonly class RegistrationSchema
{
    /** @var array<string,RegistrationField> */
    private array $fields;
    /** @var list<string> */
    private array $required;

    public function __construct(\stdClass $definition)
    {
        $configuration = get_object_vars($definition);
        if (array_diff(array_keys($configuration), ['type', 'properties', 'required', 'additionalProperties']) || 'object' !== ($configuration['type'] ?? null) || false !== ($configuration['additionalProperties'] ?? null) || !($configuration['properties'] ?? null) instanceof \stdClass || !is_array($configuration['required'] ?? null) || !array_is_list($configuration['required'])) {
            throw new \InvalidArgumentException('Unsupported registration schema.');
        }
        $fields = [];
        foreach (get_object_vars($configuration['properties']) as $key => $property) {
            $name = (string) $key;
            if ('' === trim($name) || !$property instanceof \stdClass) {
                throw new \InvalidArgumentException('Invalid registration field definition.');
            }
            $fields[$name] = new RegistrationField($property);
        }
        $required = [];
        foreach ($configuration['required'] as $key) {
            if (!is_string($key) || !array_key_exists($key, $fields) || in_array($key, $required, true)) {
                throw new \InvalidArgumentException('Invalid required registration field.');
            }
            $required[] = $key;
        }
        $this->fields = $fields;
        $this->required = $required;
    }

    /** @return array<string,RegistrationField> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return list<string> */
    public function required(): array
    {
        return $this->required;
    }

    /** @return list<string> */
    public function violations(\stdClass $values): array
    {
        $data = get_object_vars($values);
        $violations = [];
        foreach ($this->required as $key) {
            if (!array_key_exists($key, $data)) {
                $violations[] = $key;
            }
        }
        foreach ($data as $key => $value) {
            $field = $this->fields[$key] ?? null;
            if (null === $field || !$field->matches($value)) {
                $violations[] = (string) $key;
            }
        }
        sort($violations);

        return $violations;
    }
}
