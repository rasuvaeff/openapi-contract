<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Compilation;

use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaDialect;
use Rasuvaeff\OpenApiContract\Operation;

/**
 * @internal
 */
final readonly class CompiledDocument
{
    /**
     * @param list<Operation> $operations
     */
    public function __construct(
        public SchemaDialect $dialect,
        public array $operations,
    ) {}
}
