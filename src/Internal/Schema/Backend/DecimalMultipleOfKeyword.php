<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Schema\Backend;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Keyword;
use Opis\JsonSchema\Keywords\ErrorTrait;
use Opis\JsonSchema\Schema;
use Opis\JsonSchema\ValidationContext;

/**
 * The backend's `multipleOf` keyword with {@see DecimalMultiple} as its
 * arithmetic; the error it reports is the one the backend's own would.
 *
 * @internal
 */
final class DecimalMultipleOfKeyword implements Keyword
{
    use ErrorTrait;

    public function __construct(
        private readonly int|float $divisor,
    ) {}

    #[\Override]
    public function validate(ValidationContext $context, Schema $schema): ?ValidationError
    {
        /** @var mixed $data */
        $data = $context->currentData();
        if ((is_int($data) || is_float($data)) && DecimalMultiple::holds($data, $this->divisor)) {
            return null;
        }

        return $this->error($schema, $context, 'multipleOf', 'Number must be a multiple of {divisor}', ['divisor' => $this->divisor]);
    }
}
