<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Exception;

/**
 * @internal
 */
final class UnsupportedSchema extends \InvalidArgumentException
{
    public static function atKeyword(string $keyword, string $reason): self
    {
        return new self(sprintf('Unsupported schema keyword "%s": %s', $keyword, $reason));
    }
}
