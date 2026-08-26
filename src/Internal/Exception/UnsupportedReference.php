<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Exception;

use Rasuvaeff\OpenApiContract\InvalidContract;

/**
 * @internal
 */
final class UnsupportedReference extends InvalidContract
{
    public static function forValue(mixed $reference): self
    {
        return new self(sprintf(
            'Only same-document JSON Pointer references are supported, got %s',
            is_string($reference) ? sprintf('"%s"', $reference) : get_debug_type($reference),
        ));
    }
}
