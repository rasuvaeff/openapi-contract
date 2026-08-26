<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

/**
 * @api
 */
final class UnsupportedVersion extends InvalidContract
{
    public static function forVersion(string $version): self
    {
        return new self(sprintf('Unsupported OpenAPI version "%s"; supported versions are 3.0.x and 3.1.x', $version));
    }
}
