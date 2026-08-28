<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

/**
 * Immutable compiled OpenAPI operation.
 *
 * @api
 */
final readonly class Operation
{
    /**
     * @param list<array{name: non-empty-string, in: 'path'|'query'|'header'|'cookie', required: bool, style: string, explode: bool, allowReserved: bool, schema: array<string, mixed>}> $parameters
     * @param array<array-key, mixed> $requestBody
     * @param array<array-key, mixed> $responses
     * @param list<string> $serverBases
     * @param list<array<string, list<string>>> $security
     */
    public function __construct(
        public string $key,
        public ?string $operationId,
        public string $method,
        public string $path,
        public array $parameters = [],
        public array $requestBody = [],
        public array $responses = [],
        public array $serverBases = ['/'],
        public array $security = [],
    ) {}
}
