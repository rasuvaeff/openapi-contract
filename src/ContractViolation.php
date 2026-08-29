<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

/**
 * @api
 */
final class ContractViolation extends \RuntimeException
{
    public static function fromResult(ValidationResult $result): self
    {
        if ($result->isValid()) {
            return new self('OpenAPI contract validation failed');
        }

        return new self((new ValidationResultFormatter())->format($result));
    }
}
