<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Exception;

/**
 * @internal
 */
final class UnsupportedReference extends \InvalidArgumentException
{
    public static function forValue(mixed $reference): self
    {
        return new self(sprintf(
            'Only same-document JSON Pointer references are supported, got %s',
            is_string($reference) ? sprintf('"%s"', $reference) : get_debug_type($reference),
        ));
    }
}
