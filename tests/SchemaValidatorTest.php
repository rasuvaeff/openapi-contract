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
