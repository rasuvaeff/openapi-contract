<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Schema;

use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedDialect;
use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedSchema;
use Rasuvaeff\OpenApiContract\SchemaDialect;

/**
 * Normalizes the first supported OAS Schema Object subset into JSON Schema
 * Draft 2020-12 without exposing the validation backend.

 * @internal
 */
final class SchemaCompiler
{
    private const string JSON_SCHEMA_2020_12 = 'https://json-schema.org/draft/2020-12/schema';
    private const string OPENAPI_31_BASE_DIALECT = 'https://spec.openapis.org/oas/3.1/dialect/base';

    /** @var array<string, \stdClass> */
    private array $sharedSchemas = [];

    /**
     * Keywords whose value is a single subschema, mapped to whether a boolean
     * form is legal in every supported dialect.
     *
     * OAS 3.0 predates boolean schemas, so `items` and `not` admit only an
     * object there. `additionalProperties` is the exception the specification
     * itself makes — OAS 3.0.3 spells it out as "Value can be boolean or
     * object" — and `additionalProperties: false` is the commonest
     * closed-object idiom in the 3.0 corpus, so gating it made those
     * documents unusable.
     *
     * @var array<string, bool>
     */
    private const array SINGLE_SCHEMA_KEYWORDS = [
        'additionalProperties' => true,
        'items' => false,
        'not' => false,
    ];

    /** @var list<string> */
    private const array SCHEMA_LIST_KEYWORDS = [
        'allOf',
        'anyOf',
        'oneOf',
    ];

    /**
     * Keywords that re-root or re-target reference resolution. They are not
     * assertions, so ignoring them does not merely lose a check — it changes
     * what every `$ref` in the document means.
     *
     * @var list<string>
     */
    private const array UNSUPPORTED_IDENTITY_KEYWORDS = [
        '$anchor',
        '$dynamicAnchor',
        '$dynamicRef',
        '$id',
    ];

    /** @var list<string> */
    private const array SCHEMA_MAP_KEYWORDS = [
        '$defs',
        'properties',
    ];

    /** @var list<string> */
    private const array UNSUPPORTED_ASSERTION_KEYWORDS = [
        'contains',
        'contentSchema',
        'dependentRequired',
        'dependentSchemas',
        'else',
        'if',
        'patternProperties',
        'prefixItems',
        'propertyNames',
        'then',
        'unevaluatedItems',
        'unevaluatedProperties',
    ];

    /**
     * @param array<string, mixed> $schema
     */
    public function compile(array $schema, SchemaDialect $dialect): \stdClass
    {
        $compiled = $this->normalizeNode($schema, $dialect);
        $compiled['$schema'] = self::JSON_SCHEMA_2020_12;
        $json = json_encode($compiled, JSON_THROW_ON_ERROR);
        $object = json_decode($json, flags: JSON_THROW_ON_ERROR);

        if (!$object instanceof \stdClass) {
            throw new \LogicException('Compiled schema must be a JSON object');
        }

        [$shared] = $this->shareSchemaGraph($object);

        return $shared;
    }

    /**
     * Opis caches parsed nodes by object identity. Reusing identical,
     * reference-free schema nodes lets separately compiled operation roots
     * share parsed subschemas. Nodes containing `$ref` are not shared across
     * roots: a local reference is interpreted against the root's definitions.
     */
    /** @return array{0: \stdClass, 1: bool} */
    private function shareSchemaGraph(\stdClass $schema): array
    {
        $referenceFree = !property_exists($schema, '$ref');
        foreach (['additionalProperties', 'items', 'not'] as $keyword) {
            /** @var bool|\stdClass|null $member */
            $member = $schema->{$keyword} ?? null;
            if ($member instanceof \stdClass) {
                [$schema->{$keyword}, $childIsReferenceFree] = $this->shareSchemaNode($member);
                $referenceFree = $referenceFree && $childIsReferenceFree;
            }
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            /** @var mixed $members */
            $members = $schema->{$keyword} ?? null;
            if (!is_array($members)) {
                continue;
            }
            /** @var list<bool|\stdClass> $schemaMembers */
            $schemaMembers = array_values($members);
            foreach ($schemaMembers as $index => $member) {
                if (!$member instanceof \stdClass) {
                    continue;
                }
                [$members[$index], $childIsReferenceFree] = $this->shareSchemaNode($member);
                $referenceFree = $referenceFree && $childIsReferenceFree;
            }
            $schema->{$keyword} = $members;
        }
        foreach (['properties', '$defs'] as $keyword) {
            /** @var mixed $members */
            $members = $schema->{$keyword} ?? null;
            if (!$members instanceof \stdClass) {
                continue;
            }
            [$members, $childrenAreReferenceFree] = $this->shareSchemaMap($members);
            $schema->{$keyword} = $members;
            $referenceFree = $referenceFree && $childrenAreReferenceFree;
        }

        return [$this->internSchema($schema, $referenceFree), $referenceFree];
    }

    /** @return array{0: \stdClass, 1: bool} */
    private function shareSchemaNode(\stdClass $schema): array
    {
        return $this->shareSchemaGraph($schema);
    }

    /** @return array{0: \stdClass, 1: bool} */
    private function shareSchemaMap(\stdClass $schemas): array
    {
        $referenceFree = true;
        foreach (get_object_vars($schemas) as $name => $schema) {
            if (!$schema instanceof \stdClass) {
                continue;
            }
            [$schemas->{$name}, $childIsReferenceFree] = $this->shareSchemaNode($schema);
            $referenceFree = $referenceFree && $childIsReferenceFree;
        }

        return [$schemas, $referenceFree];
    }

    private function internSchema(\stdClass $schema, bool $referenceFree): \stdClass
    {
        if (!$referenceFree) {
            return $schema;
        }

        try {
            $encoded = json_encode($schema, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $schema;
        }
        $key = hash('xxh128', $encoded);
        if (isset($this->sharedSchemas[$key])) {
            return $this->sharedSchemas[$key];
        }

        return $this->sharedSchemas[$key] = $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function normalizeNode(array $schema, SchemaDialect $dialect): array
    {
        $schema = match ($dialect) {
            SchemaDialect::OpenApi30 => $this->normalizeOpenApi30($schema),
            SchemaDialect::OpenApi31 => $this->normalizeOpenApi31($schema),
        };

        foreach (self::UNSUPPORTED_ASSERTION_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                throw UnsupportedSchema::atKeyword($keyword, 'assertion is outside the v0.1 support matrix');
            }
        }

        foreach (self::UNSUPPORTED_IDENTITY_KEYWORDS as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                throw UnsupportedSchema::atKeyword($keyword, 'reference identity is outside the support matrix');
            }
        }

        foreach (self::SINGLE_SCHEMA_KEYWORDS as $keyword => $booleanAllowed) {
            if (array_key_exists($keyword, $schema)) {
                $normalized = $this->normalizeSchemaValue($schema[$keyword], $dialect, $keyword, $booleanAllowed);
                $schema = [...$schema, $keyword => $normalized];
            }
        }

        foreach (self::SCHEMA_LIST_KEYWORDS as $keyword) {
            if (!array_key_exists($keyword, $schema)) {
                continue;
            }
            if (!is_array($schema[$keyword]) || !array_is_list($schema[$keyword])) {
                throw UnsupportedSchema::atKeyword($keyword, 'expected a list of schemas');
            }

            /** @var list<mixed> $values */
            $values = $schema[$keyword];
            $normalized = array_map(
                fn(mixed $value): mixed => $this->normalizeSchemaValue($value, $dialect, $keyword),
                $values,
            );
            $schema = [...$schema, $keyword => $normalized];
        }

        foreach (self::SCHEMA_MAP_KEYWORDS as $keyword) {
            if (!array_key_exists($keyword, $schema)) {
                continue;
            }
            if (!is_array($schema[$keyword])) {
                throw UnsupportedSchema::atKeyword($keyword, 'expected an object of schemas');
            }

            /** @var array<array-key, mixed> $values */
            $values = $schema[$keyword];
            $normalizedValues = array_map(
                // A schema name is whatever the document wrote. PHP normalizes
                // a numeric-string array key to an integer, so `{"2020": …}`
                // arrives here as `int 2020` — a legal name, not a malformed
                // one. The object cast below restores it on the wire.
                //
                // The member schemas stay dialect-gated: OAS 3.0 admits a
                // boolean only for `additionalProperties`, not inside
                // `properties`.
                fn(mixed $value): bool|array|\stdClass => $this->normalizeSchemaValue($value, $dialect, $keyword),
                $values,
            );

            $schema = [...$schema, $keyword => (object) array_combine(array_keys($values), $normalizedValues)];
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function normalizeOpenApi30(array $schema): array
    {
        if (array_key_exists('$schema', $schema)) {
            throw UnsupportedDialect::forUri($this->dialectValue($schema['$schema']));
        }

        if (array_key_exists('nullable', $schema)) {
            if (!is_bool($schema['nullable'])) {
                throw UnsupportedSchema::atKeyword('nullable', 'expected a boolean');
            }
            if ($schema['nullable'] && array_key_exists('type', $schema)) {
                if (!is_string($schema['type'])) {
                    throw UnsupportedSchema::atKeyword('type', 'OAS 3.0 requires a single type');
                }
                $schema['type'] = [$schema['type'], 'null'];
            }
            unset($schema['nullable']);
        }

        $schema = $this->normalizeExclusiveBound($schema, 'minimum');

        return $this->normalizeExclusiveBound($schema, 'maximum');
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function normalizeOpenApi31(array $schema): array
    {
        if (array_key_exists('nullable', $schema)) {
            throw UnsupportedSchema::atKeyword('nullable', 'use a type union containing null in OAS 3.1');
        }
        if (!array_key_exists('$schema', $schema)) {
            return $schema;
        }

        $dialect = $this->dialectValue($schema['$schema']);
        if (!in_array($dialect, [self::JSON_SCHEMA_2020_12, self::OPENAPI_31_BASE_DIALECT], strict: true)) {
            throw UnsupportedDialect::forUri($dialect);
        }
        $schema['$schema'] = self::JSON_SCHEMA_2020_12;

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function normalizeExclusiveBound(array $schema, string $bound): array
    {
        $exclusive = 'exclusive' . ucfirst($bound);
        if (!array_key_exists($exclusive, $schema)) {
            return $schema;
        }
        if (!is_bool($schema[$exclusive])) {
            throw UnsupportedSchema::atKeyword($exclusive, 'OAS 3.0 requires a boolean');
        }
        if (!$schema[$exclusive]) {
            unset($schema[$exclusive]);

            return $schema;
        }
        if (!isset($schema[$bound]) || (!is_int($schema[$bound]) && !is_float($schema[$bound]))) {
            throw UnsupportedSchema::atKeyword($exclusive, sprintf('requires numeric %s', $bound));
        }

        $schema[$exclusive] = $schema[$bound];
        unset($schema[$bound]);

        return $schema;
    }

    /**
     * @return bool|array<string, mixed>|\stdClass
     */
    private function normalizeSchemaValue(mixed $value, SchemaDialect $dialect, string $keyword, bool $booleanAllowed = false): bool|array|\stdClass
    {
        if (is_bool($value)) {
            if (!$booleanAllowed && $dialect === SchemaDialect::OpenApi30) {
                throw UnsupportedSchema::atKeyword($keyword, 'boolean schemas require OAS 3.1');
            }

            return $value;
        }
        if (!is_array($value)) {
            throw UnsupportedSchema::atKeyword($keyword, 'expected a schema object');
        }
        if ($value !== [] && array_is_list($value)) {
            throw UnsupportedSchema::atKeyword($keyword, 'expected a schema object');
        }

        /** @var array<string, mixed> $value */
        $normalized = $this->normalizeNode($value, $dialect);

        // `{}` decodes to the empty array, which is a list to `array_is_list()`
        // and the schema with no keywords — the unconstrained one — to us; a
        // 3.0 `{nullable: true}` normalizes down to the same. It goes back on
        // the wire as `{}`, because `[]` is not a schema to the backend.
        return $normalized === [] ? new \stdClass() : $normalized;
    }

    private function dialectValue(mixed $value): string
    {
        return is_string($value) ? $value : get_debug_type($value);
    }
}
