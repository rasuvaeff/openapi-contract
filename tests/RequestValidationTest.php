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
