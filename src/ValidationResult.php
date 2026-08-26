<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

/**
 * @api
 */
final readonly class ValidationResult
{
    /** @param list<Violation> $violations */
    public function __construct(
        public array $violations = [],
    ) {}

    public function isValid(): bool
    {
        return $this->violations === [];
    }

    public function assertValid(): void
    {
        if (!$this->isValid()) {
            throw ContractViolation::fromResult($this);
        }
    }
}
