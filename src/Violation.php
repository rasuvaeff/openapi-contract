<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

/**
 * One stable, backend-independent contract violation.
 *
 * @api
 */
final readonly class Violation
{
    public function __construct(
        public string $code,
        public string $operation,
        public string $location,
        public string $instancePath,
        public string $specPointer,
        public mixed $expected,
        public mixed $actual,
        public string $message,
    ) {}
}
