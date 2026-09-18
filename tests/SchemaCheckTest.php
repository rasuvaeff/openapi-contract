<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\InvalidContract;
use Rasuvaeff\OpenApiContract\SchemaCheck;
use Rasuvaeff\OpenApiContract\SchemaDialect;
use Rasuvaeff\OpenApiContract\SchemaDirection;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(SchemaCheck::class)]
#[Covers(SchemaDirection::class)]
#[Covers(SchemaDialect::class)]
final class SchemaCheckTest
{
    private const array BODY_SCHEMA = [
        'type' => 'object',
        'required' => ['a'],
        'additionalProperties' => false,
        'properties' => ['a' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9]],
    ];

    /**
     * The check answers what request validation answers. It is the same
     * compiled schema and the same backend — a consumer building a value it
     * believes the contract rejects must not be told otherwise.
     */
    #[Property(runs: 300)]
    public function acceptsAgreesWithRequestValidation(mixed $value): void
    {
        $contract = $this->contract('3.1.0');
        $request = new ServerRequest('POST', '/items', ['Content-Type' => 'application/json'], Stream::create((string) json_encode(['a' => $value])));
        $validated = $contract->validateRequest($request)->isValid();

        Classify::cover(condition: $validated, label: 'body accepted', minPercent: 10.0);
        Classify::cover(condition: !$validated, label: 'body rejected', minPercent: 10.0);

        Assert::same($contract->accepts((object) ['a' => $value], self::BODY_SCHEMA), $validated);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function acceptsAgreesWithRequestValidationGenerators(): array
    {
        return [
            'value' => Gen::frequency([
                [3, Gen::intBetween(-4, 13)],
                [1, Gen::stringAscii()],
                [1, Gen::elements([null, true, 1.5])],
            ]),
        ];
    }

    /** @return iterable<string, array{mixed}> */
    public static function acceptsAgreesWithRequestValidationExamples(): iterable
    {
        yield 'inside the bound' => [5];
        yield 'on the lower bound' => [0];
        yield 'on the upper bound' => [9];
        yield 'below the lower bound' => [-1];
        yield 'above the upper bound' => [10];
        yield 'wrong type' => ['5'];
    }

    /**
     * A `readOnly` property is not part of a request and a `writeOnly` one is
     * not part of a response, so the same value and the same schema answer
     * differently depending on which half of the exchange is asked about.
     */
    public function theDirectionDecidesWhichPropertiesApply(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['id', 'secret'],
            'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'secret' => ['type' => 'string', 'writeOnly' => true],
            ],
        ];
        $check = new SchemaCheck();

        Assert::true($check->accepts((object) ['secret' => 's'], $schema, SchemaDialect::OpenApi31));
        Assert::false($check->accepts((object) ['id' => 1], $schema, SchemaDialect::OpenApi31));
        Assert::true($check->accepts((object) ['id' => 1], $schema, SchemaDialect::OpenApi31, SchemaDirection::Response));
        Assert::false($check->accepts((object) ['secret' => 's'], $schema, SchemaDialect::OpenApi31, SchemaDirection::Response));
    }

    /**
     * The exported rewrite is the one the validator judges by: a value is
     * accepted against the schema exactly when it is accepted against its
     * effective form, in both directions, and the effective form is a fixed
     * point of the rewrite.
     *
     * @param array<string, mixed> $schema
     */
    #[DataProvider('directionalSchemaProvider')]
    public function effectiveIsTheSchemaTheValidatorJudgesBy(array $schema, mixed $value): void
    {
        $check = new SchemaCheck();
        foreach (SchemaDirection::cases() as $direction) {
            $effective = $check->effective($schema, $direction);

            Assert::same($check->accepts($value, $effective, SchemaDialect::OpenApi31, $direction), $check->accepts($value, $schema, SchemaDialect::OpenApi31, $direction));
            Assert::same($check->effective($effective, $direction), $effective);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, mixed}> */
    public static function directionalSchemaProvider(): iterable
    {
        $item = ['type' => 'object', 'required' => ['id', 'name'], 'properties' => [
            'id' => ['type' => 'integer', 'readOnly' => true],
            'name' => ['type' => 'string', 'writeOnly' => true],
        ]];
        yield 'flat object, request-shaped value' => [$item, (object) ['name' => 'a']];
        yield 'flat object, response-shaped value' => [$item, (object) ['id' => 1]];
        yield 'flat object, mistyped foreign property' => [$item, (object) ['id' => 'x', 'name' => 'a']];
        yield 'closed object' => [[...$item, 'additionalProperties' => false], (object) ['id' => 1, 'name' => 'a']];
        yield 'list of items' => [['type' => 'array', 'items' => $item], [(object) ['name' => 'a']]];
        yield 'map of items' => [['type' => 'object', 'additionalProperties' => $item], (object) ['k' => (object) ['name' => 'a']]];
        yield 'composition' => [['allOf' => [$item, ['type' => 'object']]], (object) ['name' => 'a']];
        yield 'negation is left alone' => [['not' => $item], (object) ['name' => 'a']];
        yield 'boolean member passes through' => [['type' => 'object', 'properties' => ['open' => true], 'required' => ['open']], (object) []];
    }

    /**
     * The shape of the rewrite, pinned member by member: the foreign
     * property keeps its subschema and loses only its `required` entry, the
     * native one keeps both, and `not` is not entered.
     */
    public function effectiveUnrequiresTheForeignPropertyAndKeepsItsSubschema(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['id', 'secret'],
            'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'secret' => ['type' => 'string', 'writeOnly' => true],
            ],
            'not' => ['required' => ['id']],
        ];
        $check = new SchemaCheck();

        Assert::same($check->effective($schema, SchemaDirection::Request), [...$schema, 'required' => ['secret']]);
        Assert::same($check->effective($schema, SchemaDirection::Response), [...$schema, 'required' => ['id']]);
    }

    /**
     * OAS 3.0 spells nullability as a keyword, 3.1 as a type union. The same
     * schema is therefore read two ways: under 3.0 it admits `null`, and
     * under 3.1 `nullable` is not a keyword at all and the schema is refused
     * rather than silently read as `type: string` alone.
     */
    public function theDialectDecidesHowNullabilityIsSpelled(): void
    {
        Assert::true((new SchemaCheck())->accepts(null, ['type' => 'string', 'nullable' => true], SchemaDialect::OpenApi30));
    }

    /**
     * A document schema is checked for these at load time; a hand-passed one
     * reaches the cache key first, where `json_encode` used to leak a raw
     * `JsonException` — an exit outside `ContractException`.
     *
     * @param array<string, mixed> $schema
     */
    #[DataProvider('unencodableSchemaProvider')]
    public function refusesASchemaItCannotEncode(array $schema): void
    {
        Expect::exception(InvalidContract::class);

        (new SchemaCheck())->accepts(1, $schema, SchemaDialect::OpenApi31);
    }

    /**
     * The backend parses a node the first time a value reaches it; a `$defs`
     * member reached through a `$ref`, or a property the value happens not
     * to carry, used to be judged fine until it was not. Every node is
     * parsed at compilation, so the refusal comes from the first call.
     */
    public function refusesASubschemaTheBackendCannotParseBeforeAnyValueReachesIt(): void
    {
        $check = new SchemaCheck();
        $schema = ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/$defs/A']], '$defs' => ['A' => ['type' => 'string', 'pattern' => '[']]];

        try {
            // An object without `a` never reaches the member the backend cannot read.
            $check->accepts((object) [], $schema, SchemaDialect::OpenApi31);
            Assert::true(actual: false, message: 'Expected the schema to be refused');
        } catch (InvalidContract $exception) {
            Assert::string($exception->getMessage())->contains('pattern value must be a valid regex');
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unencodableSchemaProvider(): iterable
    {
        yield 'NAN bound' => [['type' => 'number', 'maximum' => NAN]];
        yield 'malformed UTF-8 pattern' => [['type' => 'string', 'pattern' => "\xff"]];
        $deep = ['type' => 'integer'];
        for ($level = 0; $level < 600; ++$level) {
            $deep = ['type' => 'array', 'items' => $deep];
        }
        yield 'deeper than json_encode allows' => [$deep];
    }

    /**
     * A list is not a Schema Object. Read as one it was the empty schema and
     * accepted every value, which is the fail-open a validator must not have.
     */
    public function refusesAListInPlaceOfASchema(): void
    {
        Expect::exception(InvalidContract::class);

        (new SchemaCheck())->accepts(1, ['a', 'b'], SchemaDialect::OpenApi31);
    }

    /**
     * `$ref` reaches the backend unresolved here — the compiler resolves
     * references out of a document before a schema is compiled, so a
     * hand-passed schema is the only place one can still appear. Inside the
     * schema it works; anywhere else it is refused as a contract error.
     */
    public function resolvesReferencesOnlyInsideTheSchemaItself(): void
    {
        $check = new SchemaCheck();

        Assert::true($check->accepts(5, ['$defs' => ['n' => ['type' => 'integer']], '$ref' => '#/$defs/n'], SchemaDialect::OpenApi31));
        Assert::false($check->accepts('x', ['$defs' => ['n' => ['type' => 'integer']], '$ref' => '#/$defs/n'], SchemaDialect::OpenApi31));
        foreach (['#/components/schemas/Pet', 'other.json#/Pet', 'https://example.invalid/pet.json'] as $reference) {
            try {
                $check->accepts(5, ['$ref' => $reference], SchemaDialect::OpenApi31);
            } catch (InvalidContract) {
                continue;
            }

            Assert::true(actual: false, message: sprintf('Expected "%s" to be refused', $reference));
        }
    }

    /** Under 3.1 `nullable` is not a keyword, and a schema using it is refused rather than silently read as `type: string` alone. */
    public function aThirtyNullableIsRefusedUnderThirtyOne(): void
    {
        Expect::exception(InvalidContract::class);

        (new SchemaCheck())->accepts(null, ['type' => 'string', 'nullable' => true], SchemaDialect::OpenApi31);
    }

    /**
     * The dialect travels with the operation, so a consumer holding one can
     * check a value against its schemas without the contract that compiled
     * it.
     */
    public function compilationFillsTheOperationDialect(): void
    {
        Assert::same($this->contract('3.0.3')->operation('items.create')->dialect, SchemaDialect::OpenApi30);
        Assert::same($this->contract('3.1.0')->operation('items.create')->dialect, SchemaDialect::OpenApi31);
    }

    /** A hand-built operation names no document, so it reads as the current dialect. */
    public function aHandBuiltOperationDefaultsToTheCurrentDialect(): void
    {
        Assert::same((new \Rasuvaeff\OpenApiContract\Operation(key: 'k', operationId: null, method: 'GET', path: '/'))->dialect, SchemaDialect::OpenApi31);
    }

    /** The contract binds its own dialect, so a caller never names one. */
    public function theContractBindsItsOwnDialect(): void
    {
        Assert::true($this->contract('3.0.3')->accepts(null, ['type' => 'string', 'nullable' => true]));
    }

    /** The same schema through a 3.1 contract is refused, which is the dialect binding showing. */
    public function theContractRefusesASchemaItsDialectCannotRead(): void
    {
        Expect::exception(InvalidContract::class);

        $this->contract('3.1.0')->accepts(null, ['type' => 'string', 'nullable' => true]);
    }

    private function contract(string $version): Contract
    {
        return Contract::fromArray([
            'openapi' => $version,
            'info' => ['title' => 'Items', 'version' => '1.0.0'],
            'paths' => ['/items' => ['post' => [
                'operationId' => 'items.create',
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => self::BODY_SCHEMA]]],
                'responses' => ['204' => ['description' => 'ok']],
            ]]],
        ]);
    }
}
