<?php

declare(strict_types=1);

namespace App\Domain\Registration;

final readonly class RegistrationField
{
    private RegistrationType $type;
    private ?int $minimum;
    private ?int $maximum;
    private ?int $minLength;
    private ?int $maxLength;
    /** @var list<string|int|bool>|null */
    private ?array $enumeration;

    public function __construct(\stdClass $definition)
    {
        $rule = get_object_vars($definition);
        if (array_diff(array_keys($rule), ['type', 'minimum', 'maximum', 'minLength', 'maxLength', 'enum']) || !is_string($rule['type'] ?? null) || !in_array($rule['type'], ['string', 'integer', 'boolean'], true)) {
            throw new \InvalidArgumentException('Unsupported registration field rule.');
        }
        $this->type = RegistrationType::from($rule['type']);
        $this->minimum = self::integerRule($rule, 'minimum');
        $this->maximum = self::integerRule($rule, 'maximum');
        $this->minLength = self::integerRule($rule, 'minLength');
        $this->maxLength = self::integerRule($rule, 'maxLength');
        if (((RegistrationType::Integer !== $this->type) && (null !== $this->minimum || null !== $this->maximum)) || ((RegistrationType::Text !== $this->type) && (null !== $this->minLength || null !== $this->maxLength)) || (null !== $this->minimum && null !== $this->maximum && $this->minimum > $this->maximum) || (null !== $this->minLength && $this->minLength < 0) || (null !== $this->maxLength && $this->maxLength < 0) || (null !== $this->minLength && null !== $this->maxLength && $this->minLength > $this->maxLength)) {
            throw new \InvalidArgumentException('Invalid registration bounds.');
        }
        $enumeration = null;
        if (array_key_exists('enum', $rule)) {
            if (!is_array($rule['enum']) || !array_is_list($rule['enum']) || [] === $rule['enum']) {
                throw new \InvalidArgumentException('Invalid registration enumeration.');
            }
            $enumeration = [];
            foreach ($rule['enum'] as $value) {
                if ((!is_string($value) && !is_int($value) && !is_bool($value)) || !$this->matchesType($value) || in_array($value, $enumeration, true)) {
                    throw new \InvalidArgumentException('Invalid registration enumeration value.');
                }
                $enumeration[] = $value;
            }
        }
        $this->enumeration = $enumeration;
    }

    public function type(): RegistrationType
    {
        return $this->type;
    }

    /** @return list<string|int|bool>|null */
    public function options(): ?array
    {
        return $this->enumeration;
    }

    public function matches(mixed $value): bool
    {
        if (!$this->matchesType($value)) {
            return false;
        }
        if (null !== $this->enumeration && !in_array($value, $this->enumeration, true)) {
            return false;
        }
        if (is_int($value) && ((null !== $this->minimum && $value < $this->minimum) || (null !== $this->maximum && $value > $this->maximum))) {
            return false;
        }
        if (is_string($value)) {
            $length = mb_strlen($value, 'UTF-8');
            if ((null !== $this->minLength && $length < $this->minLength) || (null !== $this->maxLength && $length > $this->maxLength)) {
                return false;
            }
        }

        return true;
    }

    private function matchesType(mixed $value): bool
    {
        return match ($this->type) {
            RegistrationType::Text => is_string($value), RegistrationType::Integer => is_int($value), RegistrationType::Boolean => is_bool($value),
        };
    }

    /** @param array<array-key,mixed> $rule */
    private static function integerRule(array $rule, string $key): ?int
    {
        if (!array_key_exists($key, $rule)) {
            return null;
        }
        if (!is_int($rule[$key])) {
            throw new \InvalidArgumentException('Registration bounds must be integers.');
        }

        return $rule[$key];
    }
}
