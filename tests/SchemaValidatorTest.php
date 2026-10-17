<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedDialect;
use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedSchema;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaCompiler;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaFailure;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaValidator;
use Rasuvaeff\OpenApiContract\SchemaDialect;
use Rasuvaeff\OpenApiContract\SchemaDirection;
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
#[Covers(SchemaFailure::class)]
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

    public function compiledRootsShareRepeatedReferenceFreeSubschemas(): void
    {
        $compiler = new SchemaCompiler();
        $first = $compiler->compile([
            'title' => 'first root',
            'properties' => ['status' => ['type' => 'string', 'enum' => ['ready', 'done']]],
        ], SchemaDialect::OpenApi31);
        $second = $compiler->compile([
            'title' => 'second root',
            'properties' => ['status' => ['type' => 'string', 'enum' => ['ready', 'done']]],
        ], SchemaDialect::OpenApi31);

        Assert::true($first->properties->status === $second->properties->status);
    }

    public function compiledRootsKeepLocalReferencesBoundToTheirOwnDefinitions(): void
    {
        $compiler = new SchemaCompiler();
        $first = $compiler->compile([
            'properties' => ['id' => ['$ref' => '#/$defs/Value']],
            '$defs' => ['Value' => ['type' => 'string']],
        ], SchemaDialect::OpenApi31);
        $second = $compiler->compile([
            'properties' => ['id' => ['$ref' => '#/$defs/Value']],
            '$defs' => ['Value' => ['type' => 'integer']],
        ], SchemaDialect::OpenApi31);

        Assert::false($first->{'$defs'} === $second->{'$defs'});
    }

    public function mixedReferenceGraphsShareOnlyTheSafeSubschemas(): void
    {
        $compiler = new SchemaCompiler();
        $schema = [
            'oneOf' => [
                ['type' => 'string'],
                ['$ref' => '#/$defs/Value'],
            ],
            '$defs' => [
                'Value' => ['type' => 'integer'],
                'Alias' => ['$ref' => '#/$defs/Value'],
            ],
        ];

        $first = $compiler->compile($schema, SchemaDialect::OpenApi31);
        $second = $compiler->compile($schema, SchemaDialect::OpenApi31);

        Assert::false($first === $second);
        Assert::true($first->oneOf[0] === $second->oneOf[0]);
        Assert::true($first->{'$defs'}->Value === $second->{'$defs'}->Value);
    }

    public function sharingWalksEverySubschemaPositionAndKeepsReferencesLocal(): void
    {
        foreach (['if', 'then', 'else', 'contains', 'unevaluatedProperties', 'unevaluatedItems', 'prefixItems', 'dependentSchemas'] as $keyword) {
            $compiler = new SchemaCompiler();
            $wrap = static fn(array $node): array => match ($keyword) {
                'prefixItems' => [$keyword => [$node]],
                'dependentSchemas' => [$keyword => ['trigger' => $node]],
                default => [$keyword => $node],
            };
            $shared = $wrap(['type' => 'string']);
            Assert::true($compiler->compile($shared, SchemaDialect::OpenApi31) === $compiler->compile($shared, SchemaDialect::OpenApi31));
            $referenced = [...$wrap(['$ref' => '#/$defs/Value']), '$defs' => ['Value' => ['type' => 'string']]];
            Assert::false($compiler->compile($referenced, SchemaDialect::OpenApi31) === $compiler->compile($referenced, SchemaDialect::OpenApi31));
        }
    }

    public function dependentRequiredPreservesNumericAndEmptyMemberMaps(): void
    {
        $validator = new SchemaValidator();
        $schema = ['type' => 'object', 'dependentRequired' => [0 => ['other']]];
        Assert::false($validator->isValid((object) ['0' => true], $schema, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid((object) ['0' => true, 'other' => true], $schema, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid((object) [], ['dependentRequired' => []], SchemaDialect::OpenApi31));
    }

    #[Property(runs: 150)]
    public function directionalRewriteReachesEveryNewPositionAndIsAFixedPoint(string $keyword, bool $response): void
    {
        $direction = $response ? SchemaDirection::Response : SchemaDirection::Request;
        $node = ['required' => ['id', 'secret'], 'properties' => [
            'id' => ['type' => 'integer', 'readOnly' => true],
            'secret' => ['type' => 'string', 'writeOnly' => true],
        ]];
        $wrap = static fn(array $member): array => match ($keyword) {
            'prefixItems' => [$keyword => [$member]],
            'dependentSchemas' => [$keyword => ['trigger' => $member]],
            default => [$keyword => $member],
        };
        $schema = $wrap($node);
        $expected = $wrap([...$node, 'required' => [$response ? 'id' : 'secret']]);
        $validator = new SchemaValidator();
        $effective = $validator->effectiveSchema($schema, $direction);

        Assert::same($effective, $expected);
        Assert::same($validator->effectiveSchema($effective, $direction), $effective);
    }

    public static function directionalRewriteReachesEveryNewPositionAndIsAFixedPointGenerators(): array
    {
        return ['keyword' => Gen::elements(['prefixItems', 'contains', 'dependentSchemas', 'unevaluatedProperties', 'unevaluatedItems']), 'response' => Gen::bool()];
    }

    #[Property(runs: 150)]
    public function directionalRewriteKeepsEvaluatedAnnotations(bool $extra, bool $response, int $id): void
    {
        $direction = $response ? SchemaDirection::Response : SchemaDirection::Request;
        $flag = $direction->foreignFlag();
        $node = ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer', $flag => true]]];
        $object = (object) ($extra ? ['id' => $id, 'extra' => true] : ['id' => $id]);
        $validator = new SchemaValidator();
        $schemasAndValues = [
            [['allOf' => [$node], 'unevaluatedProperties' => false], $object, !$extra],
            [['anyOf' => [$node, ['type' => 'null']], 'unevaluatedProperties' => false], $object, !$extra],
            [['type' => 'array', 'prefixItems' => [$node], 'unevaluatedItems' => false], [$object], true],
            [['type' => 'array', 'contains' => $node, 'unevaluatedItems' => false], [$object], true],
            [['type' => 'object', 'properties' => ['trigger' => ['type' => 'boolean']], 'dependentSchemas' => ['trigger' => $node], 'unevaluatedProperties' => false], (object) ['trigger' => true, ...get_object_vars($object)], !$extra],
        ];
        foreach ($schemasAndValues as [$schema, $value, $expected]) {
            $effective = $validator->effectiveSchema($schema, $direction);
            Assert::same($validator->isValid($value, $schema, SchemaDialect::OpenApi31, $direction), $expected);
            Assert::same($validator->isValid($value, $effective, SchemaDialect::OpenApi31, $direction), $expected);
        }
    }

    public static function directionalRewriteKeepsEvaluatedAnnotationsGenerators(): array
    {
        return ['extra' => Gen::bool(), 'response' => Gen::bool(), 'id' => Gen::int()];
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

    /**
     * `multipleOf` is judged on the decimals the document and the request
     * spell, not on the doubles PHP holds them in: `64.1` is a multiple of
     * `0.1` to the specification, one ulp off `641 × 0.1` to the backend's
     * float path, and `64.10000000000001` to its bcmath path — the verdict
     * used to depend on which extension the machine had loaded (#151).
     */
    #[DataProvider('multipleOfProvider')]
    public function judgesMultipleOfOnTheDecimalsWhateverIsLoaded(int|float $value, int|float $multiple, bool $valid): void
    {
        Assert::same(
            (new SchemaValidator())->isValid($value, ['type' => 'number', 'multipleOf' => $multiple], SchemaDialect::OpenApi31),
            $valid,
        );
    }

    public static function multipleOfProvider(): iterable
    {
        yield 'the decimal nearest 641 × 0.1' => [64.1, 0.1, true];
        yield 'the product 641 × 0.1 is not a decimal multiple' => [641 * 0.1, 0.1, false];
        yield 'a decimal at a hundred' => [123.4, 0.1, true];
        yield 'a large decimal multiple of a thousandth' => [521642427059.686, 0.001, true];
        yield 'a whole multiple' => [6.3, 0.7, true];
        yield 'an integer multiple of a decimal' => [7, 0.001, true];
        yield 'an integer multiple of a decimal with a zero fraction' => [7.0, 0.5, true];
        yield 'off by one thousandth' => [64.15, 0.1, false];
        yield 'an integer multiple' => [8, 4, true];
        yield 'not an integer multiple' => [9, 4, false];
        yield 'a negative multiple' => [-2.5, 0.5, true];
        yield 'zero' => [0, 0.3, true];
        yield 'zero as a float' => [0.0, 0.3, true];
        yield 'exponent spellings' => [1.0e25, 1.0e-7, true];
        yield 'a wide quotient is still exact' => [1.0e300, 2.5e-300, true];
        yield 'a wide quotient that is no multiple' => [1.0e300, 7.0e-7, false];
        yield 'a divisor with more trailing zeros than the value' => [1.5e-300, 3.0e10, false];
        yield 'a divisor wider than the value' => [0.3, 0.7, false];
    }

    /**
     * The law behind the table: a decimal multiple of a decimal divisor
     * holds, and the point half way to the next one never does — for every
     * width of value and divisor a document is likely to declare.
     */
    #[Property(runs: 300)]
    public function decimalMultiplesHoldAndHalfStepsDoNot(int $multiplier, int $unit, int $decimals): void
    {
        $divisor = (float) sprintf('%d.%0' . $decimals . 'd', intdiv($unit, 10 ** $decimals), $unit % (10 ** $decimals));
        $multiple = (float) sprintf('%d.%0' . $decimals . 'd', intdiv($unit * $multiplier, 10 ** $decimals), ($unit * $multiplier) % (10 ** $decimals));
        $halfStep = (float) sprintf('%d.%0' . ($decimals + 1) . 'd', intdiv($unit * (2 * $multiplier + 1), 2 * 10 ** $decimals), ($unit * (2 * $multiplier + 1)) % (2 * 10 ** $decimals) * 5);
        Classify::cover($multiple >= 64.0, 'past the float path tolerance', 30.0);
        $validator = new SchemaValidator();

        Assert::true($validator->isValid($multiple, ['type' => 'number', 'multipleOf' => $divisor], SchemaDialect::OpenApi31), sprintf('%s is a multiple of %s', json_encode($multiple), json_encode($divisor)));
        Assert::false($validator->isValid($halfStep, ['type' => 'number', 'multipleOf' => $divisor], SchemaDialect::OpenApi31), sprintf('%s is no multiple of %s', json_encode($halfStep), json_encode($divisor)));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function decimalMultiplesHoldAndHalfStepsDoNotGenerators(): array
    {
        return [
            'multiplier' => Gen::intBetween(0, 100_000),
            'unit' => Gen::intBetween(1, 9_999),
            'decimals' => Gen::intBetween(1, 3),
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
                value: '{"a":1}',
                schema: ['type' => 'string', 'contentMediaType' => 'application/json', 'contentSchema' => ['type' => 'object']],
                dialect: SchemaDialect::OpenApi31,
            );
        } catch (UnsupportedSchema $exception) {
            Assert::string($exception->getMessage())->contains('contentSchema');

            return;
        }

        Assert::true(actual: false, message: 'Expected unsupported schema exception');
    }

    public function refusesMalformedDependentRequiredAndThirtyBooleanTupleItems(): void
    {
        foreach ([
            ['dependentRequired' => ['card' => 'address']],
            ['dependentRequired' => ['card' => ['address', 1]]],
        ] as $malformed) {
            try {
                (new SchemaValidator())->isValid((object) [], $malformed, SchemaDialect::OpenApi31);
                Assert::true(actual: false, message: 'Expected malformed dependentRequired to be refused');
            } catch (UnsupportedSchema $exception) {
                Assert::string($exception->getMessage())->contains('dependentRequired');
            }
        }

        try {
            (new SchemaValidator())->isValid(['x'], ['prefixItems' => [true]], SchemaDialect::OpenApi30);
            Assert::true(actual: false, message: 'Expected a boolean tuple item to be refused under OAS 3.0');
        } catch (UnsupportedSchema $exception) {
            Assert::string($exception->getMessage())->contains('boolean schemas require OAS 3.1');
        }
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
        Assert::true($validator->isValid((object) ['k' => $value], ['type' => 'object', 'additionalProperties' => $object], SchemaDialect::OpenApi31));
        Assert::true($validator->isValid((object) ['k' => (object) ['id' => 1]], ['type' => 'object', 'additionalProperties' => $object], SchemaDialect::OpenApi31, SchemaDirection::Response));
        Assert::false($validator->isValid((object) ['k' => $value], ['type' => 'object', 'additionalProperties' => $object], SchemaDialect::OpenApi31, SchemaDirection::Response));
    }

    public function evaluatesPatternPropertiesAndPropertyNames(): void
    {
        $validator = new SchemaValidator();
        $patternProperties = [
            'type' => 'object',
            'patternProperties' => ['^x-' => ['type' => 'integer']],
            'additionalProperties' => false,
        ];
        $propertyNames = [
            'type' => 'object',
            'propertyNames' => ['pattern' => '^x-[a-z]+$'],
        ];

        Assert::true($validator->isValid((object) ['x-count' => 3], $patternProperties, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['x-count' => '3'], $patternProperties, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['count' => 3], $patternProperties, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid((object) ['x-count' => 3], $propertyNames, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['count' => 3], $propertyNames, SchemaDialect::OpenApi31));
    }

    public function patternSchemasApplyTogetherAndPropertyNamesChecksDeclaredKeys(): void
    {
        $validator = new SchemaValidator();
        $schema = [
            'type' => 'object',
            'properties' => ['x-id' => ['type' => 'integer']],
            'patternProperties' => [
                '^x-' => ['minimum' => 1],
                'id$' => ['maximum' => 5],
            ],
            'propertyNames' => ['pattern' => '^x-'],
            'additionalProperties' => true,
        ];

        Assert::true($validator->isValid((object) ['x-id' => 3], $schema, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['x-id' => 6], $schema, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['x-id' => 0], $schema, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid((object) ['x-other' => 1], $schema, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['count' => 1], $schema, SchemaDialect::OpenApi31));
    }

    public function evaluatesConditionalSchemas(): void
    {
        $validator = new SchemaValidator();
        $schema = [
            'type' => 'object',
            'required' => ['kind'],
            'properties' => ['kind' => ['type' => 'string']],
            'if' => ['properties' => ['kind' => ['const' => 'cat']]],
            'then' => ['required' => ['sound'], 'properties' => ['sound' => ['const' => 'meow']]],
            'else' => ['required' => ['sound'], 'properties' => ['sound' => ['const' => 'bark']]],
        ];

        Assert::true($validator->isValid((object) ['kind' => 'cat', 'sound' => 'meow'], $schema, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid((object) ['kind' => 'dog', 'sound' => 'bark'], $schema, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['kind' => 'cat', 'sound' => 'bark'], $schema, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['kind' => 'dog', 'sound' => 'meow'], $schema, SchemaDialect::OpenApi31));
    }

    public function evaluatesTupleAndContainsSchemasIncludingBounds(): void
    {
        $validator = new SchemaValidator();
        $tuple = [
            'type' => 'array',
            'prefixItems' => [
                ['type' => 'string', 'const' => 'v1'],
                ['type' => 'integer', 'minimum' => 1],
            ],
            'items' => false,
        ];
        $contains = [
            'type' => 'array',
            'items' => ['type' => 'integer'],
            'contains' => ['type' => 'integer', 'minimum' => 5],
            'minContains' => 2,
            'maxContains' => 3,
        ];

        Assert::true($validator->isValid(['v1', 2], $tuple, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid(['v1', 0], $tuple, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid(['v1', 2, 3], $tuple, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid([5, 6, 1], $contains, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid([5, 1], $contains, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid([5, 6, 7, 8], $contains, SchemaDialect::OpenApi31));
    }

    public function evaluatesDependentSchemasAndRequiredMembers(): void
    {
        $validator = new SchemaValidator();
        $schema = [
            'type' => 'object',
            'properties' => [
                'creditCard' => ['type' => 'string'],
                'billingAddress' => ['type' => 'string'],
                'shippingAddress' => ['type' => 'string'],
                'kind' => ['type' => 'string'],
                'taxId' => ['type' => 'string'],
            ],
            'dependentRequired' => ['creditCard' => ['billingAddress']],
            'dependentSchemas' => [
                'shippingAddress' => ['required' => ['kind']],
                'kind' => ['if' => ['properties' => ['kind' => ['const' => 'business']]], 'then' => ['required' => ['taxId']]],
            ],
        ];

        Assert::true($validator->isValid((object) [], $schema, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['creditCard' => 'x'], $schema, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid((object) ['creditCard' => 'x', 'billingAddress' => 'a'], $schema, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['shippingAddress' => 'a'], $schema, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['kind' => 'business'], $schema, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid((object) ['kind' => 'business', 'taxId' => 'x'], $schema, SchemaDialect::OpenApi31));
    }

    public function evaluatesUnevaluatedClosuresAcrossApplicators(): void
    {
        $validator = new SchemaValidator();
        $properties = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'name' => ['type' => 'string'],
            ],
            'unevaluatedProperties' => false,
        ];
        $items = [
            'type' => 'array',
            'prefixItems' => [['type' => 'string']],
            'items' => ['type' => 'integer'],
            'unevaluatedItems' => false,
        ];

        Assert::true($validator->isValid((object) ['id' => 1, 'name' => 'x'], $properties, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['id' => 1, 'name' => 'x', 'extra' => true], $properties, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid(['v1', 1, 2], $items, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid(['v1', 1, 'extra'], $items, SchemaDialect::OpenApi31));
    }

    public function directionalRewriteReachesTupleAndDependentSubschemas(): void
    {
        $validator = new SchemaValidator();
        $tuple = [
            'type' => 'array',
            'prefixItems' => [[
                'type' => 'object',
                'required' => ['id', 'name'],
                'properties' => [
                    'id' => ['type' => 'integer', 'readOnly' => true],
                    'name' => ['type' => 'string'],
                ],
            ]],
        ];
        $dependent = [
            'type' => 'object',
            'properties' => ['trigger' => ['type' => 'boolean']],
            'dependentSchemas' => ['trigger' => [
                'required' => ['id', 'name'],
                'properties' => [
                    'id' => ['type' => 'integer', 'readOnly' => true],
                    'name' => ['type' => 'string'],
                ],
            ]],
        ];

        Assert::true($validator->isValid([(object) ['name' => 'x']], $tuple, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid([(object) ['id' => 1, 'name' => 'x']], $tuple, SchemaDialect::OpenApi31, SchemaDirection::Response));
        Assert::true($validator->isValid((object) ['trigger' => true, 'name' => 'x'], $dependent, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid((object) ['trigger' => true, 'id' => 1, 'name' => 'x'], $dependent, SchemaDialect::OpenApi31, SchemaDirection::Response));
    }

    public function directionalRewriteReachesConditionalSchemas(): void
    {
        $validator = new SchemaValidator();
        $schema = [
            'if' => ['required' => ['id'], 'properties' => ['id' => ['readOnly' => true, 'type' => 'integer']]],
            'then' => ['required' => ['name'], 'properties' => ['name' => ['type' => 'string']]],
        ];

        Assert::true($validator->isValid((object) ['name' => 'x'], $schema, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid((object) ['id' => 1, 'name' => 'x'], $schema, SchemaDialect::OpenApi31, SchemaDirection::Response));
    }

    /**
     * A conditional nested in another conditional, and one under a keyword
     * the rewrite walks past: `if` is not a leaf of the schema, and a
     * document that puts one under `properties` means it as much as at the
     * top. Both used to be refused at load time as a keyword outside the
     * support matrix, so the schema was unloadable rather than misjudged.
     */
    public function evaluatesNestedConditionalSchemas(): void
    {
        $validator = new SchemaValidator();
        $schema = [
            'type' => 'object',
            'properties' => [
                'mode' => ['type' => 'string'],
                'payload' => [
                    'type' => 'object',
                    'properties' => ['kind' => ['type' => 'string'], 'detail' => ['type' => 'integer']],
                    'if' => ['required' => ['kind']],
                    'then' => ['required' => ['detail']],
                ],
            ],
            'if' => ['properties' => ['mode' => ['const' => 'strict']]],
            'then' => ['required' => ['payload'], 'properties' => ['payload' => ['required' => ['kind', 'detail']]]],
        ];

        Assert::true($validator->isValid(
            (object) ['mode' => 'strict', 'payload' => (object) ['kind' => 'x', 'detail' => 1]],
            $schema,
            SchemaDialect::OpenApi31,
        ));
        // The inner conditional demands `detail` of a payload carrying `kind`,
        // whatever the outer one says about it.
        Assert::false($validator->isValid(
            (object) ['mode' => 'lax', 'payload' => (object) ['kind' => 'x']],
            $schema,
            SchemaDialect::OpenApi31,
        ));
        // The outer one, a level higher, demands a payload of a strict request
        // and leaves a lax one free of it.
        Assert::false($validator->isValid(
            (object) ['mode' => 'strict'],
            $schema,
            SchemaDialect::OpenApi31,
        ));
        Assert::true($validator->isValid(
            (object) ['mode' => 'lax'],
            $schema,
            SchemaDialect::OpenApi31,
        ));
    }

    /**
     * The guards `SchemaCheck` relies on live here, and mutants in this class
     * are matched to this test class alone — see the package AGENTS.md on
     * `#[Covers]` — so the public-facing test in `SchemaCheckTest` cannot
     * stand in for these.
     */
    public function refusesShapesADocumentSchemaCannotTake(): void
    {
        $validator = new SchemaValidator();
        $refused = static function (array $schema) use ($validator): bool {
            try {
                $validator->isValid(1, $schema, SchemaDialect::OpenApi31);
            } catch (UnsupportedSchema) {
                return true;
            }

            return false;
        };

        Assert::true($refused(['a', 'b']));
        Assert::true($refused([0 => 'a', 'type' => 'integer']));
        Assert::true($refused(['type' => 'number', 'maximum' => NAN]));
        Assert::true($refused(['type' => 'string', 'pattern' => "\xff"]));
        Assert::false($refused([]));
        Assert::false($refused(['type' => 'integer']));
    }

    /**
     * OpenAPI defines `int32` and `int64` as ranges of the integer type. The
     * backend registers formats for strings only, so both were annotations:
     * `4294967296` satisfied `format: int32`. Every other format on a
     * non-string value stays an annotation, as the README table says.
     *
     * @param array<string, mixed> $schema
     */
    #[DataProvider('integerFormatProvider')]
    public function assertsTheIntegerFormatsAsRanges(array $schema, mixed $value, bool $valid): void
    {
        $validator = new SchemaValidator();

        Assert::same($validator->isValid($value, $schema, SchemaDialect::OpenApi31), $valid);
        Assert::same($validator->isValid($value, ['type' => 'integer', 'nullable' => true, 'format' => $schema['format']], SchemaDialect::OpenApi30), $valid);
    }

    /** @return iterable<string, array{array<string, mixed>, mixed, bool}> */
    public static function integerFormatProvider(): iterable
    {
        $int32 = ['type' => 'integer', 'format' => 'int32'];
        $int64 = ['type' => 'integer', 'format' => 'int64'];
        yield 'int32 upper bound' => [$int32, 2_147_483_647, true];
        yield 'int32 lower bound' => [$int32, -2_147_483_648, true];
        yield 'int32 above' => [$int32, 2_147_483_648, false];
        yield 'int32 below' => [$int32, -2_147_483_649, false];
        yield 'int32 whole-valued float' => [$int32, 7.0, true];
        yield 'int32 whole-valued float above' => [$int32, 4_294_967_296.0, false];
        yield 'int64 upper bound' => [$int64, PHP_INT_MAX, true];
        yield 'int64 lower bound' => [$int64, PHP_INT_MIN, true];
        // 2^63 arrives as a float: json_decode() and the parameter decoder both overflow into one.
        yield 'int64 above, as the float it decodes to' => [$int64, 9_223_372_036_854_775_808.0, false];
        yield 'int64 far above' => [$int64, 1.0e20, false];
        yield 'int64 lower bound, as the float it decodes to' => [$int64, -9_223_372_036_854_775_808.0, true];
        yield 'int64 far below' => [$int64, -1.0e20, false];
        yield 'int64 whole-valued float inside' => [$int64, 1.0e15, true];
        yield 'a string is not judged by an integer format' => [$int32, 'x', false];
        yield 'an unknown integer format stays an annotation' => [['type' => 'integer', 'format' => 'int8'], 1_000, true];
        yield 'a number format stays an annotation' => [['type' => 'number', 'format' => 'float'], 1.0e300, true];
    }

    /**
     * The backend parses a node the first time a value reaches it and wraps a
     * parse error into a schema that throws when validated. `compile()`
     * parses every node — the root and each subschema under every keyword
     * the compiler emits — so the refusal is the compilation's, not the
     * first unlucky value's.
     *
     * @param array<string, mixed> $schema
     */
    #[DataProvider('unparsableNodeProvider')]
    public function compileParsesEveryNodeTheBackendWouldParseLazily(array $schema, string $message): void
    {
        $validator = new SchemaValidator();

        try {
            $validator->compile($schema, SchemaDialect::OpenApi31, SchemaDirection::Request);
            Assert::true(actual: false, message: 'Expected compilation to refuse the schema');
        } catch (UnsupportedSchema $exception) {
            Assert::string($exception->getMessage())->contains($message);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function unparsableNodeProvider(): iterable
    {
        $bad = ['type' => 'string', 'pattern' => '['];
        yield 'the root' => [$bad, 'pattern value must be a valid regex'];
        yield 'under items' => [['type' => 'array', 'items' => $bad], 'pattern value must be a valid regex'];
        yield 'under additionalProperties' => [['type' => 'object', 'additionalProperties' => $bad], 'pattern value must be a valid regex'];
        yield 'under not' => [['not' => $bad], 'pattern value must be a valid regex'];
        foreach (['contains', 'unevaluatedProperties', 'unevaluatedItems'] as $keyword) {
            yield $keyword => [[$keyword => $bad], 'pattern value must be a valid regex'];
        }
        yield 'prefixItems' => [['prefixItems' => [$bad]], 'pattern value must be a valid regex'];
        yield 'dependentSchemas' => [['dependentSchemas' => ['trigger' => $bad]], 'pattern value must be a valid regex'];
        yield 'under oneOf, with no allOf before it' => [['oneOf' => [['type' => 'integer'], $bad]], 'pattern value must be a valid regex'];
        yield 'under anyOf' => [['anyOf' => [$bad]], 'pattern value must be a valid regex'];
        yield 'under properties, with no $defs before it' => [['type' => 'object', 'properties' => ['ok' => ['type' => 'string'], 'a' => $bad]], 'pattern value must be a valid regex'];
        yield 'under $defs, reached through $ref' => [['properties' => ['a' => ['$ref' => '#/$defs/A']], '$defs' => ['A' => $bad]], 'pattern value must be a valid regex'];
        yield 'two levels down' => [['type' => 'object', 'properties' => ['a' => ['type' => 'array', 'items' => ['allOf' => [$bad]]]]], 'pattern value must be a valid regex'];
        yield 'a minimum that is not a number, nested' => [['type' => 'object', 'properties' => ['n' => ['type' => 'integer', 'minimum' => '5']]], 'minimum must contain a valid number'];
    }

    /**
     * Compilation walks the subschemas the compiler emits; a member that is
     * not an object (a boolean schema, a scalar in a list) is not a node and
     * is left to the backend, which reads it as it always did.
     */
    public function compileLeavesNonObjectMembersToTheBackend(): void
    {
        $validator = new SchemaValidator();
        $schema = ['allOf' => [true, ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer', 'readOnly' => true]]]], 'items' => true];

        $validator->compile($schema, SchemaDialect::OpenApi31, SchemaDirection::Request);
        Assert::true($validator->isValid((object) [], $schema, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) [], $schema, SchemaDialect::OpenApi31, SchemaDirection::Response));
    }

    public function toleratesSchemasWhereEveryPropertyIsForeign(): void
    {
        $validator = new SchemaValidator();
        $readOnly = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'readOnly' => true]], 'required' => ['id']];

        Assert::true($validator->isValid((object) [], $readOnly, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid((object) ['id' => 7], $readOnly, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['id' => 'free-form'], $readOnly, SchemaDialect::OpenApi31));
        Assert::true($validator->isValid((object) [], ['type' => 'object', 'properties' => []], SchemaDialect::OpenApi31));
        Assert::false($validator->isValid('scalar', $readOnly, SchemaDialect::OpenApi31));
    }

    /**
     * A foreign property loses its `required` entry and nothing else, so the
     * verdict on a value that carries it is the same whether the object is
     * open or closed: the property is declared, so a closed object admits it,
     * and typed, so an open one still checks it. Dropping the subschema, as
     * this did, made the two disagree — the open object accepted
     * `{"id": "x"}` and the closed one rejected `{"id": 1}`.
     */
    public function aForeignPropertyStaysDeclaredAndTypedInBothOpenAndClosedObjects(): void
    {
        $validator = new SchemaValidator();
        $properties = ['id' => ['type' => 'integer', 'readOnly' => true]];
        $open = ['type' => 'object', 'properties' => $properties, 'required' => ['id']];
        $closed = ['type' => 'object', 'properties' => $properties, 'required' => ['id'], 'additionalProperties' => false];

        foreach ([$open, $closed] as $schema) {
            Assert::true($validator->isValid((object) [], $schema, SchemaDialect::OpenApi31));
            Assert::true($validator->isValid((object) ['id' => 1], $schema, SchemaDialect::OpenApi31));
            Assert::false($validator->isValid((object) ['id' => 'x'], $schema, SchemaDialect::OpenApi31));
            // The response direction keeps the requirement, and the type.
            Assert::false($validator->isValid((object) [], $schema, SchemaDialect::OpenApi31, SchemaDirection::Response));
            Assert::true($validator->isValid((object) ['id' => 1], $schema, SchemaDialect::OpenApi31, SchemaDirection::Response));
            Assert::false($validator->isValid((object) ['id' => 'x'], $schema, SchemaDialect::OpenApi31, SchemaDirection::Response));
        }
        Assert::true($validator->isValid((object) ['unrelated' => 'x'], $open, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['unrelated' => 'x'], $closed, SchemaDialect::OpenApi31));
    }

    /**
     * The rewrite recurses into the foreign property too: a `writeOnly`
     * member of a `readOnly` object is not required on a response, where the
     * object itself is.
     */
    public function rewritesTheMembersOfAForeignPropertyAsWell(): void
    {
        $validator = new SchemaValidator();
        $schema = ['type' => 'object', 'required' => ['meta'], 'properties' => [
            'meta' => ['type' => 'object', 'readOnly' => true, 'required' => ['token'], 'properties' => ['token' => ['type' => 'string', 'writeOnly' => true]]],
        ]];

        Assert::true($validator->isValid((object) ['meta' => (object) []], $schema, SchemaDialect::OpenApi31, SchemaDirection::Response));
        Assert::false($validator->isValid((object) [], $schema, SchemaDialect::OpenApi31, SchemaDirection::Response));
        Assert::true($validator->isValid((object) [], $schema, SchemaDialect::OpenApi31));
        Assert::false($validator->isValid((object) ['meta' => (object) []], $schema, SchemaDialect::OpenApi31));
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
        Assert::false($validator->isValid((object) [], $schema, SchemaDialect::OpenApi31, direction: SchemaDirection::Response));
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
            Assert::true($validator->isValid($response, $directional, SchemaDialect::OpenApi31, direction: SchemaDirection::Response));
            Assert::false($validator->isValid($request, $directional, SchemaDialect::OpenApi31, direction: SchemaDirection::Response));
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

    /**
     * The leaf failures behind a verdict (#160): where in the value, and
     * which keyword. `required` is reported per missing member at the path
     * it would have had; a leaf two branches report alike is reported once;
     * the list is bounded.
     */
    public function reportsLeafFailuresWithTheirPathAndKeyword(): void
    {
        $validator = new SchemaValidator();
        $schema = [
            'type' => 'object',
            'required' => ['a', 'b'],
            'properties' => [
                'a' => ['type' => 'integer', 'minimum' => 0],
                'list' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['n'], 'properties' => ['n' => ['type' => 'string']]]],
            ],
        ];

        Assert::same($validator->failures(json_decode('{"a":1,"b":true}'), $schema, SchemaDialect::OpenApi31), []);
        Assert::same(
            $this->render($validator->failures(json_decode('{"a":-1,"list":[{"n":"ok"},{"n":2},{}]}'), $schema, SchemaDialect::OpenApi31)),
            [
                ['b', 'required', null],
                ['a', 'minimum', -1],
                ['list.1.n', 'type', 2],
                ['list.2.n', 'required', null],
            ],
        );
        Assert::same($this->render($validator->failures('text', $schema, SchemaDialect::OpenApi31)), [['', 'type', 'text']]);
        // Each leaf carries the one assertion it failed, as the subschema spells it.
        Assert::same(
            array_map(static fn(SchemaFailure $failure): array => $failure->expected, $validator->failures(json_decode('{"a":-1,"list":[{}]}'), $schema, SchemaDialect::OpenApi31)),
            [['required' => ['a', 'b']], ['minimum' => 0], ['required' => ['n']]],
        );
        // Two leaves under one member, told apart by the rest of the path.
        Assert::same(
            $this->render($validator->failures(json_decode('{"a":1,"b":1,"list":[{"n":1},{"n":2}]}'), $schema, SchemaDialect::OpenApi31)),
            [['list.0.n', 'type', 1], ['list.1.n', 'type', 2]],
        );
        // Both branches lack the same member: once.
        Assert::same(
            $this->render($validator->failures(json_decode('{}'), ['oneOf' => [
                ['type' => 'object', 'required' => ['id']],
                ['type' => 'object', 'required' => ['id', 'name']],
            ]], SchemaDialect::OpenApi31)),
            [['id', 'required', null], ['name', 'required', null]],
        );
        $wide = $validator->failures(array_fill(0, 50, 'x'), ['type' => 'array', 'items' => ['type' => 'integer']], SchemaDialect::OpenApi31);
        Assert::same(count($wide), 20);
        Assert::same($wide[19]->path, [19]);
        // The backend's bound is per keyword; the leaves of a tree are cut to the same count.
        $properties = [];
        $value = [];
        foreach (range(0, 29) as $i) {
            $properties['p' . $i] = ['type' => 'object', 'properties' => ['q' => ['type' => 'integer'], 'r' => ['type' => 'integer']]];
            $value['p' . $i] = ['q' => 'x', 'r' => 'y'];
        }
        $deep = $validator->failures(json_decode(json_encode($value, JSON_THROW_ON_ERROR)), ['type' => 'object', 'properties' => $properties], SchemaDialect::OpenApi31);
        Assert::same(count($deep), 20);
        Assert::same($deep[19]->path, ['p9', 'r']);
    }

    /**
     * A closed object's declared member failing its own subschema is not
     * additionally reported as an `additionalProperties` failure of the
     * parent (#175): the backend marks a member as evaluated only when every
     * declared member passed, so the failing one is still unchecked when
     * `additionalProperties: false` runs. An undeclared member is a real
     * failure of it and keeps its leaf; so does a member that failed the
     * object form of `additionalProperties`, which the backend carries on
     * the member's own path.
     */
    public function closedObjectDoesNotReportItsDeclaredMemberAsAdditional(): void
    {
        $validator = new SchemaValidator();
        $closed = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['a'],
            'properties' => ['a' => ['type' => 'array', 'minItems' => 1]],
        ];

        // The declared member fails its own subschema: its leaf, and no word
        // about `additionalProperties` at the root.
        Assert::false($validator->isValid(json_decode('{"a":[]}'), $closed, SchemaDialect::OpenApi31));
        Assert::same(
            $this->render($validator->failures(json_decode('{"a":[]}'), $closed, SchemaDialect::OpenApi31)),
            [['a', 'minItems', []]],
        );

        // An undeclared member beside it is `additionalProperties`'s to report.
        Assert::same(
            $this->render($validator->failures(json_decode('{"a":[],"b":1}'), $closed, SchemaDialect::OpenApi31)),
            [['a', 'minItems', []], ['', 'additionalProperties', ['a' => [], 'b' => 1]]],
        );

        // A name `patternProperties` matches is no more additional dropped.
        $patternClosed = [
            'type' => 'object',
            'additionalProperties' => false,
            'patternProperties' => ['^x-' => ['type' => 'integer']],
            'properties' => ['x-a' => ['type' => 'integer']],
        ];
        Assert::same(
            $this->render($validator->failures(json_decode('{"x-a":"3"}'), $patternClosed, SchemaDialect::OpenApi31)),
            [['x-a', 'type', '3']],
        );
        Assert::same(
            $this->render($validator->failures(json_decode('{"x-a":"3","count":9}'), $patternClosed, SchemaDialect::OpenApi31)),
            [['x-a', 'type', '3'], ['', 'additionalProperties', ['x-a' => '3', 'count' => 9]]],
        );

        // Nested closed objects: the same at the member's own path.
        $nested = ['type' => 'object', 'additionalProperties' => false, 'properties' => ['inner' => [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['i' => ['type' => 'string', 'minLength' => 2]],
        ]]];
        Assert::same(
            $this->render($validator->failures(json_decode('{"inner":{"i":"x"}}'), $nested, SchemaDialect::OpenApi31)),
            [['inner.i', 'minLength', 'x']],
        );
        Assert::same(
            $this->render($validator->failures(json_decode('{"inner":{"i":"x","j":2}}'), $nested, SchemaDialect::OpenApi31)),
            [['inner.i', 'minLength', 'x'], ['inner', 'additionalProperties', ['i' => 'x', 'j' => 2]]],
        );

        // The object form is untouched: a member failing it as well is named
        // on its own path, under each keyword.
        $open = ['type' => 'object', 'properties' => ['s' => ['type' => 'integer']], 'additionalProperties' => ['minLength' => 5]];
        Assert::same(
            $this->render($validator->failures(json_decode('{"s":"x"}'), $open, SchemaDialect::OpenApi31)),
            [['s', 'type', 'x'], ['s', 'minLength', 'x']],
        );
    }

    /**
     * A discriminated union's diagnostics follow the branch the value names
     * — through `mapping` as a reference or a component name, or implicitly
     * by component name — and say so when it names none. The compiled form
     * is what the validator reads: a referenced branch is a local `$ref`
     * into `$defs`, named after the component's JSON Pointer.
     */
    #[DataProvider('discriminatorProvider')]
    public function followsTheDiscriminatorToOneBranch(array $discriminator, string $value, array $expected): void
    {
        $schema = [
            'oneOf' => [
                ['type' => 'object', 'required' => ['kind', 'legs'], 'properties' => ['kind' => ['const' => 'spider'], 'legs' => ['type' => 'integer']]],
                ['$ref' => '#/$defs/components.schemas.Cat', 'type' => 'object'],
                ['allOf' => [['$ref' => '#/$defs/components.schemas.Dog', 'type' => 'object'], ['required' => ['kind']]], 'type' => 'object'],
                ['$ref' => '#/$defs/pets.json:components.schemas.Fish', 'type' => 'object'],
                ['$ref' => '#/$defs/birds.json:document', 'type' => 'object'],
            ],
            'discriminator' => $discriminator,
            '$defs' => [
                'components.schemas.Cat' => ['type' => 'object', 'required' => ['kind', 'meow'], 'properties' => ['kind' => ['type' => 'string'], 'meow' => ['type' => 'string']]],
                'components.schemas.Dog' => ['type' => 'object', 'required' => ['bark'], 'properties' => ['kind' => ['type' => 'string'], 'bark' => ['type' => 'integer']]],
                'pets.json:components.schemas.Fish' => ['type' => 'object', 'required' => ['kind', 'fin'], 'properties' => ['fin' => ['type' => 'integer']]],
                'birds.json:document' => ['type' => 'object', 'required' => ['kind', 'wing'], 'properties' => ['wing' => ['type' => 'integer']]],
            ],
        ];

        Assert::same(
            $this->render((new SchemaValidator())->failures(json_decode($value), $schema, SchemaDialect::OpenApi31)),
            $expected,
        );
    }

    /** @return iterable<string, array{array<string, mixed>, string, list<array{string, string, mixed}>}> */
    public static function discriminatorProvider(): iterable
    {
        $mapping = ['propertyName' => 'kind', 'mapping' => [
            'cat' => '#/components/schemas/Cat',
            'dog' => 'Dog',
            'fish' => 'pets.json#/components/schemas/Fish',
            'bird' => 'birds.JSON',
            'spider' => 'Spider',
        ]];
        $implicit = ['propertyName' => 'kind'];
        $every = [['legs', 'required', null], ['kind', 'const', 'cat'], ['meow', 'type', 3], ['bark', 'required', null], ['fin', 'required', null], ['wing', 'required', null]];

        yield 'mapped by reference' => [$mapping, '{"kind":"cat","meow":3}', [['meow', 'type', 3]]];
        yield 'mapped by component name, branch in allOf form' => [$mapping, '{"kind":"dog","bark":"x"}', [['bark', 'type', 'x']]];
        yield 'mapped into another file' => [$mapping, '{"kind":"fish","fin":"x"}', [['fin', 'type', 'x']]];
        yield 'mapped to a whole file' => [$mapping, '{"kind":"bird","wing":"x"}', [['wing', 'type', 'x']]];
        yield 'mapped to an inline branch, which has no name' => [$mapping, '{"kind":"spider","legs":"x"}', [['kind', 'discriminator', 'spider']]];
        yield 'mapped to nothing' => [$mapping, '{"kind":"fox","meow":3}', [['kind', 'discriminator', 'fox']]];
        yield 'implicit component name' => [$implicit, '{"kind":"Cat","meow":3}', [['meow', 'type', 3]]];
        yield 'implicit name of no component' => [$implicit, '{"kind":"Fox","meow":3}', [['kind', 'discriminator', 'Fox']]];
        yield 'named branch accepts, another does too: the union is the failure' => [$implicit, '{"kind":"Cat","meow":"m","bark":1}', [['', 'oneOf', ['kind' => 'Cat', 'meow' => 'm', 'bark' => 1]]]];
        yield 'no discriminator member: every branch, the shared leaf once' => [$implicit, '{"meow":3}', [['kind', 'required', null], ['legs', 'required', null], ['meow', 'type', 3], ['bark', 'required', null], ['fin', 'required', null], ['wing', 'required', null]]];
        yield 'discriminator member not a string: every branch' => [$implicit, '{"kind":1,"meow":3}', [['legs', 'required', null], ['kind', 'const', 1], ['kind', 'type', 1], ['meow', 'type', 3], ['bark', 'required', null], ['fin', 'required', null], ['wing', 'required', null]]];
        yield 'no property name: every branch' => [['mapping' => ['cat' => 'Cat']], '{"kind":"cat","meow":3}', $every];
        yield 'not an object: the value itself' => [$implicit, '"cat"', [['', 'type', 'cat']]];
    }

    public function reportsTheDiscriminatorAsTheFailedAssertion(): void
    {
        $discriminator = ['propertyName' => 'kind', 'mapping' => ['cat' => 'Cat']];
        $schema = [
            'oneOf' => [['$ref' => '#/$defs/components.schemas.Cat', 'type' => 'object']],
            'discriminator' => $discriminator,
            '$defs' => ['components.schemas.Cat' => ['type' => 'object', 'required' => ['meow']]],
        ];

        $failures = (new SchemaValidator())->failures(json_decode('{"kind":"fox"}'), $schema, SchemaDialect::OpenApi31);
        Assert::same(count($failures), 1);
        Assert::same($failures[0]->expected, ['discriminator' => $discriminator]);
    }

    public function followsANestedDiscriminatorAtItsOwnPath(): void
    {
        $schema = ['type' => 'object', 'properties' => ['pets' => ['type' => 'array', 'items' => [
            'anyOf' => [['$ref' => '#/$defs/components.schemas.Cat', 'type' => 'object']],
            'discriminator' => ['propertyName' => 'kind'],
        ]]], '$defs' => ['components.schemas.Cat' => ['type' => 'object', 'required' => ['meow'], 'properties' => ['meow' => ['type' => 'string']]]]];

        Assert::same(
            $this->render((new SchemaValidator())->failures(json_decode('{"pets":[{"kind":"Cat","meow":"m"},{"kind":"Cat"},{"kind":"Fox"}]}'), $schema, SchemaDialect::OpenApi31)),
            [['pets.1.meow', 'required', null], ['pets.2.kind', 'discriminator', 'Fox']],
        );
    }

    /**
     * @param list<SchemaFailure> $failures
     * @return list<array{string, string, mixed}>
     */
    private function render(array $failures): array
    {
        return array_map(
            static fn(SchemaFailure $failure): array => [
                implode('.', $failure->path),
                $failure->keyword,
                $failure->actual instanceof \stdClass ? (array) $failure->actual : $failure->actual,
            ],
            $failures,
        );
    }
}
