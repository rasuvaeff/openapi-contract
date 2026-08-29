<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests\Differential;

use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Differential smoke corpus (§12): the same exchange is judged by this
 * package and by league/openapi-psr7-validator. Agreement is asserted where
 * the semantics overlap; every deliberate divergence is pinned with the
 * decision that explains it, so a silent behavioral drift on either side
 * fails this suite instead of going unnoticed.
 */
#[Test]
#[CoversNothing]
final class LeagueDifferentialTest
{
    #[DataProvider('responseCorpus')]
    public function responseVerdictsMatchTheCorpus(ResponseInterface $response, bool $ours, bool $league): void
    {
        Assert::same($this->contract()->validateExchange(new ServerRequest('GET', '/items?limit=5'), $response)->isValid(), $ours);
        Assert::same($this->leagueAcceptsResponse($response), $league);
    }

    #[DataProvider('requestCorpus')]
    public function requestVerdictsMatchTheCorpus(ServerRequestInterface $request, bool $ours, bool $league): void
    {
        parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query);

        Assert::same($this->contract()->validateRequest($request)->isValid(), $ours);
        Assert::same($this->leagueAcceptsRequest($request), $league);
    }

    /** @return iterable<string, array{ResponseInterface, bool, bool}> */
    public static function responseCorpus(): iterable
    {
        $body = static fn(array $overrides = []): string => json_encode(array_merge(['id' => 1, 'name' => 'x'], $overrides), JSON_THROW_ON_ERROR);

        yield 'valid response' => [new Response(200, ['Content-Type' => 'application/json'], $body()), true, true];
        yield 'wrong property type' => [new Response(200, ['Content-Type' => 'application/json'], $body(['id' => 'one'])), false, false];
        yield 'missing required property' => [new Response(200, ['Content-Type' => 'application/json'], '{"id":1}'), false, false];
        yield 'null for non-nullable' => [new Response(200, ['Content-Type' => 'application/json'], $body(['name' => null])), false, false];
        yield 'null for nullable' => [new Response(200, ['Content-Type' => 'application/json'], $body(['tag' => null])), true, true];
        yield 'valid date-time format' => [new Response(200, ['Content-Type' => 'application/json'], $body(['created_at' => '2026-08-29T10:00:00+00:00'])), true, true];
        // Pinned divergence: opis asserts `format: date-time`, cebe treats it
        // as an annotation. We keep the strict side — this exact assertion
        // caught a raw database timestamp leaking through a real API.
        yield 'invalid date-time format' => [new Response(200, ['Content-Type' => 'application/json'], $body(['created_at' => '2026-08-29 10:00:00+00'])), false, true];
        yield 'undeclared status' => [new Response(404, ['Content-Type' => 'application/json'], '{"error":"x"}'), false, false];
        yield 'undeclared media type' => [new Response(200, ['Content-Type' => 'text/plain'], 'hello'), false, false];
        yield 'malformed json body' => [new Response(200, ['Content-Type' => 'application/json'], '{broken'), false, false];
    }

    /** @return iterable<string, array{ServerRequestInterface, bool, bool}> */
    public static function requestCorpus(): iterable
    {
        $post = static fn(array $payload): ServerRequestInterface => new ServerRequest(
            'POST',
            '/items',
            ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        yield 'valid request' => [$post(['name' => 'a', 'kind' => 'cat']), true, true];
        yield 'missing required body property' => [$post(['name' => 'a']), false, false];
        yield 'enum violation' => [$post(['name' => 'a', 'kind' => 'fox']), false, false];
        yield 'readOnly property in request' => [$post(['name' => 'a', 'kind' => 'cat', 'id' => 5]), true, true];
        yield 'minLength violation' => [$post(['name' => '', 'kind' => 'cat']), false, false];
        yield 'missing required query parameter' => [new ServerRequest('GET', '/items'), false, false];
        yield 'wrong query parameter type' => [new ServerRequest('GET', '/items?limit=abc'), false, false];
        yield 'query parameter below minimum' => [new ServerRequest('GET', '/items?limit=0'), false, false];
    }

    /** @return array<string, mixed> */
    private static function document(): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'differential', 'version' => '1'],
            'paths' => [
                '/items' => [
                    'get' => [
                        'operationId' => 'items.list',
                        'parameters' => [
                            ['name' => 'limit', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1]],
                        ],
                        'responses' => [
                            '200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => [
                                'type' => 'object',
                                'required' => ['id', 'name'],
                                'properties' => [
                                    'id' => ['type' => 'integer'],
                                    'name' => ['type' => 'string'],
                                    'tag' => ['type' => 'string', 'nullable' => true],
                                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                                    'secret' => ['type' => 'string', 'readOnly' => true],
                                ],
                            ]]]],
                        ],
                    ],
                    'post' => [
                        'operationId' => 'items.create',
                        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'required' => ['name', 'kind'],
                            'properties' => [
                                'name' => ['type' => 'string', 'minLength' => 1],
                                'kind' => ['type' => 'string', 'enum' => ['cat', 'dog']],
                                'id' => ['type' => 'integer', 'readOnly' => true],
                            ],
                        ]]]],
                        'responses' => ['201' => ['description' => 'created']],
                    ],
                ],
            ],
        ];
    }

    private function contract(): Contract
    {
        return Contract::fromArray(self::document());
    }

    private function leagueAcceptsResponse(ResponseInterface $response): bool
    {
        $validator = (new ValidatorBuilder())
            ->fromJson(json_encode(self::document(), JSON_THROW_ON_ERROR))
            ->getResponseValidator();

        try {
            $validator->validate(new OperationAddress('/items', 'get'), $response);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function leagueAcceptsRequest(ServerRequestInterface $request): bool
    {
        $validator = (new ValidatorBuilder())
            ->fromJson(json_encode(self::document(), JSON_THROW_ON_ERROR))
            ->getServerRequestValidator();

        try {
            $validator->validate($request);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }
}
