<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Benchmarks;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Testo\Bench;

/**
 * The hot path. Everything a contract does per message happens here — parameter
 * deserialization, body decoding, and schema validation — and this is the loop a
 * consumer runs once per request, or a generator runs a few hundred times per
 * property.
 *
 * The `fresh contract per call` baseline compiles the document again for every
 * message, which is both what a naive consumer writes and roughly what this
 * package charged internally before schema compilation was cached. The
 * comparison is the point of the benchmark, not the absolute number.
 */
final class ValidateRequestBench
{
    private static ?Contract $contract = null;

    private static ?ServerRequestInterface $request = null;

    #[Bench(
        callables: ['fresh contract per call' => [self::class, 'validateWithFreshContract']],
        calls: 2_000,
        iterations: 5,
    )]
    public static function validateRequest(): bool
    {
        return (self::$contract ??= self::contract())
            ->validateRequest(self::$request ??= self::request())
            ->isValid();
    }

    /** Baseline: a contract compiled anew for every message. */
    public static function validateWithFreshContract(): bool
    {
        return self::contract()->validateRequest(self::request())->isValid();
    }

    private static function request(): ServerRequestInterface
    {
        return new ServerRequest(
            'POST',
            '/items/42?limit=5&tags=red,blue',
            ['Content-Type' => 'application/json', 'X-Tenant' => 'public'],
            '{"name":"Milo","kind":"cat","weight":4.25,"tags":["a","b"]}',
        );
    }

    private static function contract(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'info' => ['title' => 'bench', 'version' => '1'],
            'paths' => ['/items/{id}' => ['post' => [
                'parameters' => [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ['name' => 'limit', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    ['name' => 'tags', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => false,
                        'schema' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1]],
                    ['name' => 'X-Tenant', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 1]],
                ],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => ['name', 'kind'],
                    'properties' => [
                        'name' => ['type' => 'string', 'minLength' => 1],
                        'kind' => ['type' => 'string', 'enum' => ['cat', 'dog']],
                        'weight' => ['type' => 'number', 'minimum' => 0],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ]]]],
                'responses' => ['200' => ['description' => 'ok']],
            ]]],
        ]);
    }
}
