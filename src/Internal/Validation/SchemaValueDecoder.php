<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Validation;

use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterKind;
use Rasuvaeff\OpenApiContract\InvalidContract;

/**
 * @internal
 */
final readonly class SchemaValueDecoder
{
    /**
     * The wire shape a value of this schema takes. OAS 3.1 spells a type as a
     * union, so membership decides, not identity. A union naming both `array`
     * and `object` cannot be told apart on the wire — see the class docblock on
     * {@see \Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterCodec} —
     * so the list shape wins, deliberately, rather than raising from a place
     * whose callers do not all turn an exception into a violation.
     *
     * @param array<string, mixed> $schema
     */
    public function kind(array $schema): ParameterKind
    {
        /** @var mixed $type */
        $type = $schema['type'] ?? 'string';
        $types = is_array($type) ? $type : [$type];
        if (in_array('array', $types, strict: true)) {
            return ParameterKind::List;
        }
        if (in_array('object', $types, strict: true)) {
            return ParameterKind::Object;
        }

        return ParameterKind::Scalar;
    }

    /**
     * @param string|list<string>|array<string, string> $value
     * @param array<string, mixed> $schema
     */
    public function coerce(string|array $value, array $schema): string|int|float|bool|array|object|null
    {
        if (is_string($value)) {
            return $this->scalar($value, $schema);
        }
        if (array_is_list($value)) {
            /** @var mixed $itemsValue */
            $itemsValue = $schema['items'] ?? null;
            $items = $this->schema($itemsValue) ?? [];

            return array_map(fn(string $item): mixed => $this->scalar($item, $items), $value);
        }
        /** @var mixed $propertiesValue */
        $propertiesValue = $schema['properties'] ?? null;
        $properties = is_array($propertiesValue) ? $propertiesValue : [];
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($item)) {
                continue;
            }
            /** @var mixed $propertyValue */
            $propertyValue = $properties[$key] ?? null;
            $property = $this->schema($propertyValue) ?? [];
            $result[$key] = $this->scalar($item, $property);
        }

        return (object) $result;
    }

    /**
     * The Schema Object a value carries, or `null` when it carries none of
     * the shape this decoder reads. A boolean schema is a schema — it just
     * has no keywords to decode with, and the backend enforces it — so it
     * answers `null` here instead of raising out of a validation call.
     *
     * A shape that is neither raises `InvalidContract`: the document said
     * something this package cannot read, which is a contract error and not a
     * fault of the message being validated. The compiler rejects every such
     * declaration it can see, so this exit is reachable only for a schema
     * nested inside another one and for a hand-built `Operation`.
     *
     * @return array<string, mixed>|null
     */
    public function schema(mixed $value): ?array
    {
        if ($value === null || is_bool($value)) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidContract('Schema must be an object');
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new InvalidContract('Schema keys must be strings');
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * The Schema Objects a `properties` map declares, keyed by the property
     * name as it appears on the wire — PHP normalizes a numeric-string array
     * key to an integer, so the name is cast back. A boolean member declares
     * a property without decoding keywords and maps to the unconstrained
     * schema; the backend still enforces what the boolean says.
     *
     * @param array<string, mixed> $schema
     * @return array<string, array<string, mixed>>
     */
    public function properties(array $schema): array
    {
        /** @var mixed $value */
        $value = $schema['properties'] ?? [];
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach (array_keys($value) as $name) {
            /** @var mixed $member */
            $member = $value[$name] ?? null;
            if ($member === null) {
                continue;
            }
            $result[(string) $name] = is_bool($member) ? [] : ($this->schema($member) ?? []);
        }

        return $result;
    }

    /** @param array<string, mixed> $schema */
    public function scalar(string $value, array $schema): string|int|float|bool|null
    {
        /** @var mixed $type */
        $type = $schema['type'] ?? 'string';
        $types = is_array($type) ? $type : [$type];
        if (in_array('null', $types, strict: true) && $value === 'null') {
            return null;
        }
        if (in_array('integer', $types, strict: true) && preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value) === 1) {
            return (int) $value;
        }
        if (in_array('number', $types, strict: true) && is_numeric($value)) {
            return (float) $value;
        }
        if (in_array('boolean', $types, strict: true) && ($value === 'true' || $value === 'false')) {
            return $value === 'true';
        }

        return $value;
    }
}
