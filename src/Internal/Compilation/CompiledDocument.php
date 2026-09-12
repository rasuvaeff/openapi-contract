<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Compilation;

use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\OpenApiContract\SchemaDialect;

/**
 * @psalm-import-type CompiledSecurityScheme from Contract
 *
 * @internal
 */
final readonly class CompiledDocument
{
    /**
     * @param list<Operation> $operations
     * @param array<string, CompiledSecurityScheme> $securitySchemes
     */
    public function __construct(
        public SchemaDialect $dialect,
        public array $operations,
        public array $securitySchemes,
    ) {}
}
