<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\ContractViolation;
use Rasuvaeff\OpenApiContract\Internal\Validation\RequestValidator;
use Rasuvaeff\OpenApiContract\MatchedOperation;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\OpenApiContract\ValidationResult;
use Rasuvaeff\OpenApiContract\Violation;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Contract::class)]
#[Covers(RequestValidator::class)]
#[Covers(Operation::class)]
#[Covers(MatchedOperation::class)]
#[Covers(ValidationResult::class)]
#[Covers(Violation::class)]
#[Covers(ContractViolation::class)]
final class RequestValidationTest
{
    public function validatesParametersAndJsonBody(): void
    {
        $request = new ServerRequest('POST', '/pets/42?tag=small&tag=friendly', [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Tenant' => 'public',
        ], '{"name":"Milo"}');

        $result = $this->contract()->validateRequest($request);

        Assert::true($result->isValid());
        $result->assertValid();
    }

    public function aggregatesIndependentRequestViolations(): void
    {
        $request = new ServerRequest('POST', '/pets/not-an-integer', [
            'Content-Type' => 'application/json',
        ], '{broken');

        $result = $this->contract()->validateRequest($request);

        Assert::false($result->isValid());
        Assert::same(
            array_map(static fn(Violation $violation): string => $violation->code, $result->violations),
            ['request.parameter.schema', 'request.parameter.missing', 'request.body.json'],
        );
        Expect::exception(ContractViolation::class);
        $result->assertValid();
    }

    public function preservesSeekableBodyPosition(): void
    {
        $request = new ServerRequest('POST', '/pets/42', [
            'Content-Type' => 'application/json',
            'X-Tenant' => 'public',
        ], '{"name":"Milo"}');
        $request->getBody()->seek(5);

        $this->contract()->validateRequest($request);

        Assert::same($request->getBody()->tell(), 5);
    }

    public function reportsUnknownOperationWithoutCascadingErrors(): void
    {
        $result = $this->contract()->validateRequest(new ServerRequest('GET', '/missing'));

        Assert::same(count($result->violations), 1);
        Assert::same($result->violations[0]->code, 'request.operation.unknown');
    }

    public function contractViolationSummarizesFirstViolationAndHandlesEmptyResult(): void
    {
        $violation = new Violation(
            code: 'request.invalid',
            operation: 'pets.get',
            location: 'query',
            instancePath: 'q',
            specPointer: '/paths/pets',
            expected: 'string',
            actual: 1,
            message: 'bad query',
        );
        $exception = ContractViolation::fromResult(new ValidationResult([$violation]));
        Assert::same($exception->getMessage(), 'OpenAPI contract validation failed with 1 violation(s): [request.invalid] bad query');
        Assert::same(ContractViolation::fromResult(new ValidationResult())->getMessage(), 'OpenAPI contract validation failed');
    }

    public function acceptsExplodedAdditionalPropertiesObject(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/search' => ['get' => [
                'parameters' => [[
                    'name' => 'filter', 'in' => 'query', 'required' => true,
                    'style' => 'form', 'explode' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => ['kind' => ['type' => 'string']],
                        'additionalProperties' => ['type' => 'string'],
                    ],
                ]],
                'responses' => ['200' => []],
            ]]],
        ]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/search?kind=animal&term=cat'))->isValid());
    }

    public function matchesWildcardRequestMediaType(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/wild' => ['post' => [
                'requestBody' => ['required' => true, 'content' => ['*/*' => ['schema' => ['type' => 'object']]]],
                'responses' => ['204' => []],
            ]]],
        ]);

        $request = new ServerRequest('POST', '/wild', ['Content-Type' => 'application/json'], '{}');
        Assert::true($contract->validateRequest($request)->isValid());
    }

    public function reportsMissingRequiredBodyAndMediaType(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/body' => ['post' => [
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
                'responses' => ['204' => []],
            ]]],
        ]);

        Assert::same($contract->validateRequest(new ServerRequest('POST', '/body'))->violations[0]->code, 'request.body.missing');
        Assert::same($contract->validateRequest(new ServerRequest('POST', '/body', ['Content-Type' => 'text/plain'], '{}'))->violations[0]->code, 'request.body.media_type');
    }

    public function reportsMalformedAndSchemaInvalidJsonBodies(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/body' => ['post' => [
                'requestBody' => ['required' => false, 'content' => ['application/problem+json' => ['schema' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]]]],
                'responses' => ['204' => []],
            ]]],
        ]);

        Assert::same($contract->validateRequest(new ServerRequest('POST', '/body', ['Content-Type' => 'application/problem+json'], '{broken'))->violations[0]->code, 'request.body.json');
        Assert::same($contract->validateRequest(new ServerRequest('POST', '/body', ['Content-Type' => 'application/problem+json'], '{"id":"wrong"}'))->violations[0]->code, 'request.body.schema');
    }

    public function reportsOptionalMissingParameterAndSerializationErrors(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/items' => ['get' => [
                'parameters' => [
                    ['name' => 'required', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']],
                    ['name' => 'tags', 'in' => 'query', 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ],
                'responses' => ['200' => []],
            ]]],
        ]);

        $missing = $contract->validateRequest(new ServerRequest('GET', '/items'));
        Assert::same(array_map(static fn(Violation $v): string => $v->code, $missing->violations), ['request.parameter.missing']);
        $invalid = $contract->validateRequest(new ServerRequest('GET', '/items?required=x&tags=a%2Fb%2Cc'));
        Assert::false($invalid->isValid());
    }

    public function readOnlyAndWriteOnlyPropertiesFollowRequestDirection(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/items' => ['post' => [
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => ['id', 'name'],
                    'properties' => [
                        'id' => ['type' => 'integer', 'readOnly' => true],
                        'name' => ['type' => 'string', 'writeOnly' => true],
                    ],
                ]]]],
                'responses' => ['204' => []],
            ]]],
        ]);

        $request = new ServerRequest('POST', '/items', ['Content-Type' => 'application/json'], '{"name":"ok"}');
        Assert::true($contract->validateRequest($request)->isValid());
    }

    private function contract(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => [
                '/pets/{id}' => [
                    'post' => [
                        'operationId' => 'pets.update',
                        'parameters' => [
                            [
                                'name' => 'id',
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'integer', 'minimum' => 1],
                            ],
                            [
                                'name' => 'tag',
                                'in' => 'query',
                                'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                            [
                                'name' => 'X-Tenant',
                                'in' => 'header',
                                'required' => true,
                                'schema' => ['type' => 'string'],
                            ],
                        ],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['name'],
                                        'properties' => ['name' => ['type' => 'string']],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['200' => []],
                    ],
                ],
            ],
        ]);
    }
}
