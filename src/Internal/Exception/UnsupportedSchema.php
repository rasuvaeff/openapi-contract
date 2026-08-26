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
}
