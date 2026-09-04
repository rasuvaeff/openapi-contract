<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\ContractViolation;
use Rasuvaeff\OpenApiContract\ValidationResult;
use Rasuvaeff\OpenApiContract\ValidationResultFormatter;
use Rasuvaeff\OpenApiContract\Violation;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ContractViolation::class)]
#[Covers(ValidationResultFormatter::class)]
final class ValidationResultFormatterTest
{
    public function formatsEveryViolationAndFieldInStableOrder(): void
    {
        $result = new ValidationResult([
            new Violation(
                code: 'request.parameter.schema',
                operation: 'pets.get',
                location: 'query',
                instancePath: 'page',
                specPointer: '/paths/~1pets/get/parameters/0/schema',
                expected: ['type' => 'integer'],
                actual: 'top-secret',
                message: "Query parameter is invalid\nwithout injecting a line",
            ),
            new Violation(
                code: 'response.status.mismatch',
                operation: 'pets.get',
                location: 'status',
                instancePath: '$',
                specPointer: '/paths/~1pets/get/responses',
                expected: [200, 201],
                actual: 500,
                message: 'Response status is not declared',
            ),
        ]);

        Assert::same((new ValidationResultFormatter())->format($result), <<<'TEXT'
            OpenAPI contract validation failed with 2 violation(s)
            1. code: "request.parameter.schema"
               operation: "pets.get"
               location: "query"
               instancePath: "page"
               specPointer: "/paths/~1pets/get/parameters/0/schema"
               expected: {"type":"integer"}
               actual: "[redacted]"
               message: "Query parameter is invalid\nwithout injecting a line"
            2. code: "response.status.mismatch"
               operation: "pets.get"
               location: "status"
               instancePath: "$"
               specPointer: "/paths/~1pets/get/responses"
               expected: [200,201]
               actual: 500
               message: "Response status is not declared"
            TEXT);
    }

    public function boundsNestedAndLongValues(): void
    {
        $long = str_repeat('a', 600);
        $result = new ValidationResult([new Violation(
            code: 'request.parameter.schema',
            operation: 'body.get',
            location: 'path',
            instancePath: 'id',
            specPointer: '/paths/~1body/get/parameters/0/schema',
            expected: ['a' => ['b' => ['c' => [
                'object' => new \DateTimeImmutable('@0'),
                'scalar' => 'kept',
            ]]]],
            actual: $long,
            message: 'Body does not match its schema',
        )]);

        $formatted = (new ValidationResultFormatter())->format($result);

        Assert::false(str_contains($formatted, $long));
        Assert::string($formatted)->contains(
            'expected: {"a":{"b":{"c":{"object":"[depth limit]","scalar":"kept"}}}}',
        );
        Assert::string($formatted)->contains('actual: "' . str_repeat('a', 508) . '...');
    }

    public function preservesValuesAtTheExactByteLimitAndJsonFlags(): void
    {
        $formatted = (new ValidationResultFormatter())->format(new ValidationResult([
            new Violation(
                code: 'request.parameter.schema',
                operation: 'body.get',
                location: 'path',
                instancePath: 'id',
                specPointer: '/paths',
                expected: 1.0,
                actual: str_repeat('a', 510),
                message: 'exact boundary',
            ),
            new Violation(
                code: 'request.parameter.schema',
                operation: 'body.get',
                location: 'path',
                instancePath: 'id',
                specPointer: '/paths',
                expected: 'a/b',
                actual: "invalid\xFFutf8",
                message: 'flags',
            ),
        ]));

        Assert::string($formatted)
            ->contains('expected: 1.0')
            ->contains('actual: "' . str_repeat('a', 510) . '"')
            ->contains('expected: "a/b"')
            ->contains('actual: "invalid\\ufffdutf8"');
    }

    public function sortsMapsAndLimitsArrayItems(): void
    {
        $formatted = (new ValidationResultFormatter())->format(new ValidationResult([
            new Violation(
                code: 'request.parameter.schema',
                operation: 'body.get',
                location: 'path',
                instancePath: 'id',
                specPointer: '/paths',
                expected: ['b' => 2, 'a' => 1],
                actual: range(1, 18),
                message: 'collection bounds',
            ),
        ]));

        Assert::string($formatted)
            ->contains('expected: {"a":1,"b":2}')
            ->contains('actual: [1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,"[item limit]"]');
    }

    /**
     * A body is user data of unknown shape, and a whole-body violation carries
     * the instance path `$` — the name pattern has nothing to inspect. The
     * value is redacted on the location alone, in either direction.
     */
    #[DataProvider('bodyViolationLocations')]
    public function redactsBodyValuesWhateverTheyContain(string $code): void
    {
        $formatted = (new ValidationResultFormatter())->format(new ValidationResult([
            new Violation(
                code: $code,
                operation: 'login',
                location: 'body',
                instancePath: '$',
                specPointer: '/paths',
                expected: ['type' => 'object'],
                actual: ['user' => 'ada', 'password' => 'hunter2'],
                message: 'Body does not match its schema',
            ),
        ]));

        Assert::false(str_contains($formatted, 'hunter2'));
        Assert::string($formatted)
            ->contains('actual: "[redacted]"')
            ->contains('expected: {"type":"object"}');
    }

    /** @return iterable<string, array{string}> */
    public static function bodyViolationLocations(): iterable
    {
        yield 'request' => ['request.body.schema'];
        yield 'response' => ['response.body.schema'];
    }

    public function redactsSensitiveNamesCaseInsensitively(): void
    {
        $formatted = (new ValidationResultFormatter())->format(new ValidationResult([
            new Violation(
                code: 'response.body.schema',
                operation: 'body.get',
                location: 'body',
                instancePath: 'AUTHORIZATION',
                specPointer: '/paths',
                expected: 'string',
                actual: 'top-secret',
                message: 'sensitive value',
            ),
        ]));

        Assert::false(str_contains($formatted, 'top-secret'));
        Assert::string($formatted)->contains('actual: "[redacted]"');
    }

    public function contractViolationUsesTheFormatterAndHandlesEmptyResults(): void
    {
        $result = new ValidationResult([new Violation(
            code: 'request.invalid',
            operation: 'pets.get',
            location: 'request',
            instancePath: '$',
            specPointer: '/paths',
            expected: 'valid request',
            actual: null,
            message: 'Request is invalid',
        )]);

        Assert::same(
            ContractViolation::fromResult($result)->getMessage(),
            (new ValidationResultFormatter())->format($result),
        );
        Assert::same(
            ContractViolation::fromResult(new ValidationResult())->getMessage(),
            'OpenAPI contract validation failed',
        );
        Assert::same(
            (new ValidationResultFormatter())->format(new ValidationResult()),
            'OpenAPI contract validation passed',
        );
    }
}
