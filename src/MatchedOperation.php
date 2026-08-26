<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

/**
 * @api
 */
final readonly class MatchedOperation
{
    /**
     * @param array<string, string> $pathParameters
     */
    public function __construct(
        public Operation $operation,
        public array $pathParameters,
    ) {}
}
