<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;
use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedSchema;
use Rasuvaeff\OpenApiContract\Internal\Schema\Backend\DecimalMultiple;
use Rasuvaeff\OpenApiContract\Internal\Schema\Backend\DecimalMultipleOfKeywordParser;
use Rasuvaeff\OpenApiContract\Internal\Schema\Backend\Draft;
use Rasuvaeff\OpenApiContract\Internal\Schema\Backend\Parser;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaValidator;
use Rasuvaeff\OpenApiContract\SchemaDialect;
use Rasuvaeff\OpenApiContract\SchemaDirection;
use Rasuvaeff\OpenApiContract\Tests\Support\Refusal;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(DecimalMultiple::class)]
#[Covers(DecimalMultipleOfKeywordParser::class)]
#[Covers(Draft::class)]
#[Covers(SchemaValidator::class)]
final class SchemaBackendTest
{
    #[DataProvider('decimalProvider')]
    public function decidesDivisibilityOnTheDecimalsDirectly(int|float $value, int|float $divisor, bool $expected): void
    {
        Assert::same(DecimalMultiple::holds($value, $divisor), $expected);
    }

    /** @return iterable<string, array{int|float, int|float, bool}> */
    public static function decimalProvider(): iterable
    {
        yield 'integers that divide' => [12, 4, true];
        yield 'integers that do not divide' => [13, 4, false];
        yield 'an integer divisor of zero' => [5, 0, false];
        yield 'zero is a multiple of every integer' => [0, 7, true];
        yield 'the lowest integer by minus one' => [PHP_INT_MIN, -1, true];
        yield 'a float value against an integer divisor of zero' => [5.5, 0, false];
        yield 'an infinite value' => [INF, 1.0, false];
        yield 'a negative infinite value' => [-INF, 1.0, false];
        yield 'a value that is not a number' => [NAN, 1.0, false];
        yield 'an infinite divisor' => [1.0, INF, false];
        yield 'a divisor that is not a number' => [1.0, NAN, false];
        yield 'an infinite divisor against an integer' => [3, INF, false];
        yield 'a float divisor of zero' => [1.5, 0.0, false];
        yield 'a negative float divisor of zero' => [1.5, -0.0, false];
        yield 'one is a multiple of one' => [5.0, 1, true];
        yield 'a float multiple of one' => [5.5, 1, false];
        yield 'an integer value against a half' => [7, 0.5, true];
        yield 'an integer value against one and a half' => [9, 1.5, true];
        yield 'an integer value against a tenth of its own width' => [7, 0.3, false];
        yield 'zero against a decimal' => [0.0, 0.3, true];
        yield 'zero against a divisor with trailing zeros' => [0.0, 10.0, true];
        yield 'integer zero against a divisor with trailing zeros' => [0, 10.0, true];
        yield 'one against a decimal' => [1.0, 0.3, false];
        yield 'one against one tenth' => [1.0, 0.1, true];
        yield 'a negative value' => [-12.0, 4.0, true];
        yield 'a negative value that does not divide' => [-13.0, 4.0, false];
        yield 'a negative divisor' => [12.0, -4.0, true];
        yield 'trailing zeros shared with the divisor' => [200.0, 50.0, true];
        yield 'trailing zeros that leave a remainder' => [250.0, 100.0, false];
        yield 'a divisor ending in zero after scaling' => [15.0, 10.0, false];
        yield 'a large power of ten' => [1.0e20, 1.0e10, true];
        yield 'a small power of ten' => [1.0e-7, 1.0e-8, true];
        yield 'a small power of ten that does not divide' => [1.5e-7, 1.0e-7, false];
        yield 'an uppercase exponent spelling is read the same' => [2.5e+20, 5.0e+19, true];
        yield 'many digits' => [123456789012.345, 0.005, true];
        yield 'many digits, off by the last one' => [123456789012.346, 0.005, false];
        yield 'a multi-digit remainder' => [12345.0, 7.0, false];
        yield 'a multi-digit multiple' => [12348.0, 7.0, true];
        yield 'a leading-zero fraction' => [0.05, 0.01, true];
        yield 'a leading-zero fraction that does not divide' => [0.055, 0.01, false];
        yield 'the lowest integer against a float divisor' => [PHP_INT_MIN, 2.0, true];
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('invalidMultipleOfProvider')]
    public function refusesAMultipleOfThatIsNotAPositiveNumber(array $schema, string $message): void
    {
        [$class, $actual] = Refusal::of(static fn() => (new SchemaValidator())->compile($schema, SchemaDialect::OpenApi31, SchemaDirection::Request));

        Assert::same($class, UnsupportedSchema::class);
        Assert::string($actual)->contains($message);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidMultipleOfProvider(): iterable
    {
        yield 'zero' => [['multipleOf' => 0], 'multipleOf must be greater than zero'];
        yield 'zero as a float' => [['multipleOf' => 0.0], 'multipleOf must be greater than zero'];
        yield 'negative' => [['multipleOf' => -1], 'multipleOf must be greater than zero'];
        yield 'negative as a float' => [['multipleOf' => -0.5], 'multipleOf must be greater than zero'];
        yield 'a string' => [['multipleOf' => '2'], 'multipleOf must be a valid number (integer or float)'];
        yield 'a boolean' => [['multipleOf' => true], 'multipleOf must be a valid number (integer or float)'];
        yield 'null' => [['multipleOf' => null], 'multipleOf must be a valid number (integer or float)'];
    }

    public function acceptsTheSmallestPositiveDivisors(): void
    {
        $validator = new SchemaValidator();

        Assert::true($validator->isValid(0.0, ['multipleOf' => 1.0e-300], SchemaDialect::OpenApi31));
        Assert::true($validator->isValid(3, ['multipleOf' => 1], SchemaDialect::OpenApi31));
        Assert::false($validator->isValid(3, ['multipleOf' => 2], SchemaDialect::OpenApi31));
    }

    public function reportsTheDivisorThatWasBroken(): void
    {
        $failures = (new SchemaValidator())->failures(7, ['multipleOf' => 0.5], SchemaDialect::OpenApi31);

        Assert::same(count($failures), 0);

        $failures = (new SchemaValidator())->failures(7.25, ['multipleOf' => 0.5], SchemaDialect::OpenApi31);

        Assert::same(count($failures), 1);
        Assert::same($failures[0]->keyword, 'multipleOf');
        Assert::same($failures[0]->expected, ['multipleOf' => 0.5]);
        Assert::same($failures[0]->actual, 7.25);
    }

    public function theBackendErrorNamesTheDivisorThatWasBroken(): void
    {
        $validator = new Validator(new SchemaLoader(parser: new Parser()));

        $error = $validator->validate(7.25, (object) ['multipleOf' => 0.5])->error();

        Assert::same($error?->keyword(), 'multipleOf');
        Assert::same($error?->args(), ['divisor' => 0.5]);
        Assert::true($validator->validate(7.5, (object) ['multipleOf' => 0.5])->isValid());
    }

    public function aNonNumberIsNotAMultipleOfAnything(): void
    {
        $validator = new SchemaValidator();

        Assert::true($validator->isValid('7', ['multipleOf' => 2], SchemaDialect::OpenApi31));
        Assert::true($validator->isValid(null, ['multipleOf' => 2], SchemaDialect::OpenApi31));
    }

    /**
     * Backend features the compiler leaves in the document as annotations must
     * stay annotations: none of them is an OpenAPI keyword, and none of them is
     * allowed to change what a schema accepts or to make a document refuse.
     *
     * @param array<string, mixed> $schema
     */
    #[DataProvider('backendOnlyKeywordProvider')]
    public function leavesBackendOnlyKeywordsAsAnnotations(array $schema): void
    {
        $validator = new SchemaValidator();

        $validator->compile($schema, SchemaDialect::OpenApi31, SchemaDirection::Request);

        Assert::true($validator->isValid('ab', $schema, SchemaDialect::OpenApi31));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function backendOnlyKeywordProvider(): iterable
    {
        yield 'a filter that does not exist' => [['type' => 'string', '$filters' => ['$func' => 'nonexistent']]];
        yield 'a filter of the wrong shape' => [['type' => 'string', '$filters' => 5]];
        yield 'a pragma of the wrong shape' => [['type' => 'string', '$pragma' => 5]];
        yield 'slots of the wrong shape' => [['type' => 'string', '$slots' => 5]];
        yield 'a function keyword' => [['type' => 'string', '$func' => 5]];
        yield 'a mapper' => [['type' => 'string', '$map' => 5]];
        yield 'a global' => [['type' => 'string', '$vars' => 5]];
        yield 'an error template' => [['type' => 'string', 'minLength' => 1, '$error' => ['x' => 5]]];
    }

    public function doesNotResolveDataReferencesInAKeywordValue(): void
    {
        $validator = new SchemaValidator();

        [$class, $message] = Refusal::of(static fn() => $validator->compile(
            ['type' => 'string', 'minLength' => ['$data' => '/x']],
            SchemaDialect::OpenApi31,
            SchemaDirection::Request,
        ));

        Assert::same($class, UnsupportedSchema::class);
        Assert::string($message)->contains('minLength');
    }

    public function appliesNoSchemaDefaultToTheValueItJudges(): void
    {
        $value = new \stdClass();
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'default' => 'x']]];

        Assert::true((new SchemaValidator())->isValid($value, $schema, SchemaDialect::OpenApi31));
        Assert::same((array) $value, []);
    }

    public function refusesADanglingReferenceWhenTheValueIsJudged(): void
    {
        $validator = new SchemaValidator();
        $schema = ['properties' => ['a' => ['$ref' => '#/$defs/Missing']]];

        [$class] = Refusal::of(static fn() => $validator->isValid((object) ['a' => 1], $schema, SchemaDialect::OpenApi31));

        Assert::same($class, UnsupportedSchema::class);
    }
}
