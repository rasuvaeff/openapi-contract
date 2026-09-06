<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Benchmarks;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\MatchedOperation;
use Testo\Bench;

/**
 * Matching against a document the size of a real API. Routes are bucketed by
 * method, segment count and first literal segment, so finding an operation
 * does not walk the document. The baseline is what a consumer writes when they
 * look one up themselves — a scan over `operations()` — which is also what this
 * package did internally until the bucketing landed.
 */
final class MatchOperationBench
{
    private const int OPERATIONS = 1_000;

    private static ?Contract $contract = null;

    private static ?ServerRequestInterface $request = null;

    #[Bench(
        callables: ['scan operations() by hand' => [self::class, 'findByScanning']],
        calls: 20_000,
        iterations: 5,
    )]
    public static function matchInLargeDocument(): bool
    {
        return (self::$contract ??= self::contract(self::OPERATIONS))
            ->match(self::$request ??= self::request()) instanceof MatchedOperation;
    }

    /** Baseline: the lookup a consumer writes over the public operation list. */
    public static function findByScanning(): bool
    {
        $contract = self::$contract ??= self::contract(self::OPERATIONS);
        $path = (self::$request ??= self::request())->getUri()->getPath();
        foreach ($contract->operations() as $operation) {
            if ($operation->method !== 'GET') {
                continue;
            }
            $pattern = '#^' . preg_replace('/\\{[^{}]+\\}/', '[^/]+', preg_quote($operation->path, '#')) . '$#';
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function request(): ServerRequestInterface
    {
        return new ServerRequest('GET', '/resource3/42');
    }

    private static function contract(int $operations): Contract
    {
        $paths = [];
        for ($index = 0; $index < $operations; $index++) {
            $paths["/resource{$index}/{id}"] = ['get' => [
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                'responses' => ['200' => ['description' => 'ok']],
            ]];
        }

        return Contract::fromArray(['openapi' => '3.1.0', 'paths' => $paths]);
    }
}
