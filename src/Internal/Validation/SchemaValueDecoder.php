<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Validation;

use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterKind;

/**
 * @internal
 */
final readonly class SchemaValueDecoder
{
    /** @param array<string, mixed> $schema */
    public function kind(array $schema): ParameterKind
    {
        /** @var mixed $type */
        $type = $schema['type'] ?? 'string';

        return match ($type) {
            'array' => ParameterKind::List,
            'object' => ParameterKind::Object,
            default => ParameterKind::Scalar,
        };
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

    /** @return array<string, mixed>|null */
    public function schema(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('Schema must be an object');
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Schema keys must be strings');
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
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
