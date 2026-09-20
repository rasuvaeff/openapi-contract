<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Schema;

/**
 * One leaf failure returned by the schema backend.
 *
 * @internal
 */
final readonly class SchemaFailure
{
    /**
     * @param list<string|int> $path
     */
    public function __construct(
        public array $path,
        public string $keyword,
        public mixed $actual,
        public string $message,
    ) {}
}
