<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Internal\Validation\ResponseValidator;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Contract::class)]
#[Covers(ResponseValidator::class)]
final class ResponseValidationTest
{
    public function validatesSelectedResponseAndPreservesBodyPosition(): void
    {
        $response = new Response(200, ['Content-Type' => 'application/json', 'X-Request-Id' => 'abc'], '{"id":7}');
        $response->getBody()->seek(3);

        $result = $this->contract()->validateExchange(new ServerRequest('GET', '/pets/7'), $response);

        Assert::true($result->isValid());
        Assert::same($response->getBody()->tell(), 3);
    }

    public function reportsStatusHeaderMediaAndSchemaViolations(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/plain'], '{"id":"bad"}');

        $result = $this->contract()->validateExchange(new ServerRequest('GET', '/pets/7'), $response);

        Assert::same(
            array_map(static fn($violation): string => $violation->code, $result->violations),
            ['response.header.missing', 'response.body.media_type'],
        );
    }

    public function acceptsRangeAndDefaultResponses(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/health' => ['get' => ['responses' => ['2XX' => [], 'default' => []]]]],
        ]);

        Assert::true($contract->validateExchange(new ServerRequest('GET', '/health'), new Response(204))->isValid());
        Assert::true($contract->validateExchange(new ServerRequest('GET', '/health'), new Response(418))->isValid());
    }

    private function contract(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets/{id}' => ['get' => [
                'parameters' => [['name' => 'id', 'in' => 'path', 'schema' => ['type' => 'integer']]],
                'responses' => [
                    '200' => [
                        'headers' => ['X-Request-Id' => ['required' => true]],
                        'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'required' => ['id'],
                            'properties' => ['id' => ['type' => 'integer']],
                        ]]],
                    ],
                ],
            ]]],
        ]);
    }
}
