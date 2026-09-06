<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedDialect;
use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedSchema;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaCompiler;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaDialect;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaValidator;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(SchemaValidator::class)]
#[Covers(SchemaCompiler::class)]
#[Covers(UnsupportedSchema::class)]
#[Covers(SchemaDialect::class)]
final class SchemaValidatorTest
{
    #[DataProvider('dialectProvider')]
    public function validatesEffectiveSchema(
        SchemaDialect $dialect,
        array $schema,
        mixed $valid,
        mixed $invalid,
    ): void {
        $validator = new SchemaValidator();

        Assert::true($validator->isValid($valid, $schema, $dialect));
        Assert::false($validator->isValid($invalid, $schema, $dialect));
    }

    /** @return iterable<string, array{SchemaDialect, array<string, mixed>, mixed, mixed}> */
    public static function dialectProvider(): iterable
    {
        yield 'oas 3.0 nullable and exclusive minimum' => [
            SchemaDialect::OpenApi30,
            ['type' => 'integer', 'nullable' => true, 'minimum' => 2, 'exclusiveMinimum' => true],
            3,
            2,
        ];
        yield 'oas 3.1 union and numeric exclusive minimum' => [
            SchemaDialect::OpenApi31,
            ['type' => ['integer', 'null'], 'exclusiveMinimum' => 2],
            3,
            2,
        ];
    }

    #[Property(runs: 100)]
    public function integerBoundsRemainValid(int $value): void
    {
        Classify::cover($value < 3, 'below-bound', 20.0);
        Classify::cover($value >= 3, 'at-or-above-bound', 20.0);

        Assert::same(
            (new SchemaValidator())->isValid(
                $value,
                ['type' => 'integer', 'minimum' => 3],
                SchemaDialect::OpenApi31,
            ),
            $value >= 3,
        );
    }

    /** @return array<string, ArbitraryInterface> */
    public static function integerBoundsRemainValidGenerators(): array
    {
        return ['value' => Gen::intBetween(-10, 10)];
    }

    public function rejectsUnknownDialect(): void
    {
        try {
            (new SchemaValidator())->isValid(
                1,
                ['$schema' => 'https://example.test/unknown'],
                SchemaDialect::OpenApi31,
            );
        } catch (UnsupportedDialect $exception) {
            Assert::string($exception->getMessage())->contains('https://example.test/unknown');

            return;
        }

        Assert::true(actual: false, message: 'Expected unsupported dialect exception');
    }

    public function rejectsOas30BooleanSchema(): void
    {
        try {
            (new SchemaValidator())->isValid(
                value: true,
                schema: ['items' => false],
                dialect: SchemaDialect::OpenApi30,
            );
        } catch (UnsupportedSchema $exception) {
            Assert::string($exception->getMessage())->contains('boolean schemas require OAS 3.1');

            return;
        }

        Assert::true(actual: false, message: 'Expected unsupported schema exception');
    }

    public function rejectsUnsupportedAssertionKeyword(): void
    {
        try {
            (new SchemaValidator())->isValid(
                value: ['a'],
                schema: ['type' => 'array', 'prefixItems' => [['type' => 'string']]],
                dialect: SchemaDialect::OpenApi31,
            );
        } catch (UnsupportedSchema $exception) {
            Assert::string($exception->getMessage())->contains('prefixItems');

            return;
        }

        Assert::true(actual: false, message: 'Expected unsupported schema exception');
    }

    public function filtersDirectionalPropertiesInsideNestedStructures(): void
    {
        $validator = new SchemaValidator();
        $object = [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'readOnly' => true], 'name' => ['type' => 'string']],
            'required' => ['id'],
        ];
        $value = (object) ['name' => 'x'];

        Assert::true($validator->isValid($value, $object, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid([$value], ['type' => 'array', 'items' => $object], SchemaDialect::OpenApi31));
        Assert::true($validator->isValid($value, ['allOf' => [$object]], SchemaDialect::OpenApi31));
    }

    public function toleratesSchemasWhereEveryPropertyIsFilteredOut(): void
    {
        $validator = new SchemaValidator();
        $readOnly = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'readOnly' => true]], 'required' => ['id']];

        Assert::true($validator->isValid((object) [], $readOnly, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid((object) ['id' => 'free-form'], $readOnly, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid((object) [], ['type' => 'object', 'properties' => []], SchemaDialect::OpenApi31));
        Assert::false($validator->isValid('scalar', $readOnly, SchemaDialect::OpenApi31));
    }

    /**
     * Dropping the last property drops `properties` itself, so what the
     * document says about undeclared properties is what still decides: a
     * closed object stays closed, and an open one stays open. Pinned because
     * the open half reads like a hole in a fail-closed package and is not
     * one — OAS implies no `additionalProperties: false`.
     */
    public function filteringEveryPropertyLeavesTheDocumentsOwnOpennessIntact(): void
    {
        $validator = new SchemaValidator();
        $properties = ['id' => ['type' => 'integer', 'readOnly' => true]];
        $open = ['type' => 'object', 'properties' => $properties];
        $closed = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];

        Assert::true($validator->isValid((object) [], $open, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid((object) ['id' => 1], $open, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid((object) ['unrelated' => 'x'], $open, SchemaDialect::OpenApi31));

        Assert::true($validator->isValid((object) [], $closed, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['id' => 1], $closed, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['unrelated' => 'x'], $closed, SchemaDialect::OpenApi31));
    }

    /**
     * OAS 3.0.3 spells `additionalProperties` out as "Value can be boolean or
     * object". Grouping it with `items` and `not` — which really do forbid a
     * boolean before 3.1 — made the commonest closed-object idiom in the 3.0
     * corpus throw out of every validation call.
     */
    public function acceptsBooleanAdditionalPropertiesUnderOas30(): void
    {
        $validator = new SchemaValidator();
        $closed = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'additionalProperties' => false];

        Assert::true($validator->isValid((object) ['a' => 'x'], $closed, SchemaDialect::OpenApi30));
        Assert::false($validator->isValid((object) ['a' => 'x', 'b' => 1], $closed, SchemaDialect::OpenApi30));
        Assert::true($validator->isValid((object) ['b' => 1], ['type' => 'object', 'additionalProperties' => true], SchemaDialect::OpenApi30));
    }

    /**
     * `items` and `not` keep the 3.1 gate: only `additionalProperties` is
     * exempt, and only because the 3.0 specification says so.
     */
    #[DataProvider('oas30BooleanSchemaProvider')]
    public function rejectsBooleanSchemaWhereOas30Forbids(array $schema): void
    {
        try {
            (new SchemaValidator())->isValid(value: true, schema: $schema, dialect: SchemaDialect::OpenApi30);
        } catch (UnsupportedSchema $exception) {
            Assert::string($exception->getMessage())->contains('boolean schemas require OAS 3.1');

            return;
        }

        Assert::true(actual: false, message: 'Expected unsupported schema exception');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function oas30BooleanSchemaProvider(): iterable
    {
        yield 'items' => [['items' => false]];
        yield 'not' => [['not' => true]];
        yield 'properties member' => [['type' => 'object', 'properties' => ['a' => true]]];
    }

    /**
     * PHP normalizes the array key `"2020"` to `int 2020`, so a legal
     * `properties: {"2020": …}` used to be dropped as a malformed name — and
     * its `required` entry with it, which made the whole declaration silently
     * unchecked in both directions.
     */
    public function validatesPropertiesWhoseNameIsNumeric(): void
    {
        $validator = new SchemaValidator();
        $schema = ['type' => 'object', 'properties' => ['2020' => ['type' => 'integer']], 'required' => ['2020']];

        Assert::true($validator->isValid((object) ['2020' => 1], $schema, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) [], $schema, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['2020' => 'x'], $schema, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) [], $schema, SchemaDialect::OpenApi31, direction: 'response'));
    }

    /**
     * Numeric names that happen to run 0, 1, 2 make `properties` a PHP list,
     * which JSON-encodes as an array unless the map is cast back to an object.
     */
    public function validatesPropertiesWhoseNamesFormAList(): void
    {
        $validator = new SchemaValidator();
        $schema = [
            'type' => 'object',
            'properties' => ['0' => ['type' => 'integer'], '1' => ['type' => 'string']],
            'required' => ['0'],
        ];

        Assert::true($validator->isValid((object) ['0' => 1, '1' => 'x'], $schema, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['0' => 'not-an-integer'], $schema, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['1' => 'x'], $schema, SchemaDialect::OpenApi31));
    }

    /**
     * A boolean member of `properties` is a schema, not a form to skip:
     * `false` admits no value for that property at all, `true` admits any.
     */
    public function validatesBooleanPropertySchemas(): void
    {
        $validator = new SchemaValidator();
        $forbidden = ['type' => 'object', 'properties' => ['secret' => false]];
        $anything = ['type' => 'object', 'properties' => ['a' => true], 'required' => ['a']];

        Assert::true($validator->isValid((object) [], $forbidden, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['secret' => 1], $forbidden, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid((object) ['a' => 'anything'], $anything, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) [], $anything, SchemaDialect::OpenApi31));
    }

    /**
     * The directional filter is the only reason a `required` entry is
     * dropped. An entry naming a property the filter passed through untouched
     * has to survive, or the requirement disappears from the check.
     */
    public function keepsRequiredEntriesOfPropertiesItDoesNotRecurseInto(): void
    {
        $validator = new SchemaValidator();
        $schema = [
            'type' => 'object',
            'properties' => ['open' => true, 'id' => ['type' => 'integer', 'readOnly' => true]],
            'required' => ['open', 'id'],
        ];

        Assert::true($validator->isValid((object) ['open' => 'x'], $schema, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) [], $schema, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['id' => 1], $schema, SchemaDialect::OpenApi31));
    }

    /**
     * Compilation is cached per instance, so the cache key has to carry
     * everything that changes what the compiled form asserts. Direction and
     * dialect both do: the same schema drops different properties for a
     * request and a response, and means different things under 3.0 and 3.1.
     */
    public function cachesCompilationWithoutMergingDirectionsOrDialects(): void
    {
        $validator = new SchemaValidator();
        $directional = [
            'type' => 'object',
            'required' => ['id', 'secret'],
            'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'secret' => ['type' => 'string', 'writeOnly' => true],
            ],
        ];
        $request = (object) ['secret' => 'x'];
        $response = (object) ['id' => 1];

        // Twice each, so a second call reads the cache rather than compiling.
        foreach ([1, 2] as $ignored) {
            Assert::true($validator->isValid($request, $directional, SchemaDialect::OpenApi31));
            Assert::false($validator->isValid($response, $directional, SchemaDialect::OpenApi31));
            Assert::true($validator->isValid($response, $directional, SchemaDialect::OpenApi31, direction: 'response'));
            Assert::false($validator->isValid($request, $directional, SchemaDialect::OpenApi31, direction: 'response'));
        }

        // `nullable` is an OAS 3.0 keyword and is rejected under 3.1: caching
        // the 3.0 compilation must not answer the 3.1 call from the cache.
        $nullable = ['type' => 'string', 'nullable' => true];
        Assert::true($validator->isValid(null, $nullable, SchemaDialect::OpenApi30));
        Assert::true($validator->isValid(null, $nullable, SchemaDialect::OpenApi30));

        try {
            $validator->isValid(null, $nullable, SchemaDialect::OpenApi31);
        } catch (UnsupportedSchema $exception) {
            Assert::string($exception->getMessage())->contains('nullable');

            return;
        }

        Assert::true(actual: false, message: 'Expected the 3.1 dialect to reject the cached 3.0 schema');
    }

    public function normalizesNestedOas30SchemasInEveryContainer(): void
    {
        $validator = new SchemaValidator();
        $nullable = ['type' => 'string', 'nullable' => true];

        Assert::true($validator->isValid(null, ['allOf' => [$nullable]], SchemaDialect::OpenApi30));
        Assert::true($validator->isValid(null, ['anyOf' => [$nullable]], SchemaDialect::OpenApi30));
        Assert::true($validator->isValid((object) ['a' => null], ['type' => 'object', 'properties' => ['a' => $nullable]], SchemaDialect::OpenApi30));
    }

    public function nullableInteractsWithTheDeclaredType(): void
    {
        $validator = new SchemaValidator();

        Assert::false($validator->isValid(null, ['type' => 'string', 'nullable' => false], SchemaDialect::OpenApi30));
        Assert::true($validator->isValid(null, ['type' => 'string', 'nullable' => true], SchemaDialect::OpenApi30));
        Assert::true($validator->isValid('x', ['nullable' => true], SchemaDialect::OpenApi30));
        Assert::true($validator->isValid(null, ['nullable' => true], SchemaDialect::OpenApi30));
    }

    public function normalizesOas30ExclusiveBoundsAtTheBoundary(): void
    {
        $validator = new SchemaValidator();
        $schema = ['type' => 'integer', 'minimum' => 5, 'exclusiveMinimum' => true];

        Assert::false($validator->isValid(5, $schema, SchemaDialect::OpenApi30));
        Assert::true($validator->isValid(6, $schema, SchemaDialect::OpenApi30));

        $float = ['type' => 'number', 'minimum' => 1.5, 'exclusiveMinimum' => true];
        Assert::false($validator->isValid(1.5, $float, SchemaDialect::OpenApi30));
        Assert::true($validator->isValid(1.6, $float, SchemaDialect::OpenApi30));
    }

    public function rejectsExclusiveFlagsWithoutNumericBounds(): void
    {
        $validator = new SchemaValidator();

        try {
            $validator->isValid(1, ['type' => 'integer', 'exclusiveMinimum' => true], SchemaDialect::OpenApi30);
            Assert::true(actual: false, message: 'Expected unsupported schema exception');
        } catch (UnsupportedSchema) {
            Assert::true(actual: true);
        }

        try {
            $validator->isValid(1, ['type' => 'integer', 'minimum' => '5', 'exclusiveMinimum' => true], SchemaDialect::OpenApi30);
            Assert::true(actual: false, message: 'Expected unsupported schema exception');
        } catch (UnsupportedSchema) {
            Assert::true(actual: true);
        }
    }

    public function ignoresSchemaDefaultsDuringValidation(): void
    {
        $schema = ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'integer', 'default' => 1]]];

        Assert::false((new SchemaValidator())->isValid((object) [], $schema, SchemaDialect::OpenApi31));
    }

    public function rejectsUnknownDirection(): void
    {
        try {
            (new SchemaValidator())->isValid(1, [], SchemaDialect::OpenApi31, direction: 'other');
        } catch (\InvalidArgumentException $exception) {
            Assert::string($exception->getMessage())->contains('Unknown schema direction');

            return;
        }

        Assert::true(actual: false, message: 'Expected invalid direction exception');
    }

}
