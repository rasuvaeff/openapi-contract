<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Compilation;

use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaDialect;
use Rasuvaeff\OpenApiContract\Operation;

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
