<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Response;

/**
 * @internal
 */
final readonly class SelectedResponse
{
    /**
     * @param non-empty-string $key
     * @param array<string, mixed> $definition
     */
    public function __construct(
        public string $key,
        public array $definition,
    ) {}
}
