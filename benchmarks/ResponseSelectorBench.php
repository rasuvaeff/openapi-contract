<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Benchmarks;

use Rasuvaeff\OpenApiContract\Internal\Response\ResponseSelector;
use Rasuvaeff\OpenApiContract\Internal\Response\SelectedResponse;
use Testo\Bench;

final class ResponseSelectorBench
{
    /** @var array<array-key, mixed> */
    private const array RESPONSES = [
        '2XX' => ['description' => 'range'],
        'default' => ['description' => 'fallback'],
    ];

    private static ?ResponseSelector $selector = null;

    #[Bench(
        callables: ['new selector per call' => [self::class, 'selectWithFreshSelector']],
        arguments: [self::RESPONSES, 204],
        calls: 100_000,
        iterations: 10,
    )]
    public static function selectRangeResponse(array $responses, int $status): ?SelectedResponse
    {
        return (self::$selector ??= new ResponseSelector())->select($responses, $status);
    }

    /**
     * Baseline that includes constructing the stateless selector per call.
     * The production path reuses the immutable selector instance.
     *
     * @param array<array-key, mixed> $responses
     */
    public static function selectWithFreshSelector(array $responses, int $status): ?SelectedResponse
    {
        return (new ResponseSelector())->select($responses, $status);
    }
}
