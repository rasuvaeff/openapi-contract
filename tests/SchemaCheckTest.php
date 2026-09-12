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
     * OAS 3.0 spells nullability as a keyword, 3.1 as a type union. The same
     * schema is therefore read two ways: under 3.0 it admits `null`, and
     * under 3.1 `nullable` is not a keyword at all and the schema is refused
     * rather than silently read as `type: string` alone.
     */
    public function theDialectDecidesHowNullabilityIsSpelled(): void
    {
        Assert::true((new SchemaCheck())->accepts(null, ['type' => 'string', 'nullable' => true], SchemaDialect::OpenApi30));
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
