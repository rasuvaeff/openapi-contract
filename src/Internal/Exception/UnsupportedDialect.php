<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Exception;

use Rasuvaeff\OpenApiContract\InvalidContract;

/**
 * @internal
 */
final class UnsupportedDialect extends InvalidContract
{
    public static function forUri(string $uri): self
    {
        return new self(sprintf('Unsupported JSON Schema dialect "%s"', $uri));
    }
}
