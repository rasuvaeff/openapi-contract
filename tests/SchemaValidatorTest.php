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
