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
        $first = $result->violations[0] ?? null;
        if (!$first instanceof Violation) {
            return new self('OpenAPI contract validation failed');
        }

        return new self(sprintf(
            'OpenAPI contract validation failed with %d violation(s): [%s] %s',
            count($result->violations),
            $first->code,
            $first->message,
        ));
    }
}
