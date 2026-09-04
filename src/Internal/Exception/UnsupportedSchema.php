<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Exception;

use Rasuvaeff\OpenApiContract\InvalidContract;

/**
 * @internal
 */
final class UnsupportedSchema extends InvalidContract
{
    public static function atKeyword(string $keyword, string $reason): self
    {
        return new self(sprintf('Unsupported schema keyword "%s": %s', $keyword, $reason));
    }

    public static function fromBackend(\Throwable $previous): self
    {
        return new self(
            sprintf('Schema cannot be evaluated by the validation backend: %s', $previous->getMessage()),
            previous: $previous,
        );
    }
}
