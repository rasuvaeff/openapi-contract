<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests\Support;

use Rasuvaeff\OpenApiContract\ValidationResult;
use Rasuvaeff\OpenApiContract\Violation;

/**
 * A violation reduced to the fields that say where and why, so a scenario
 * table can pin all of them at once.
 */
final readonly class ViolationRow
{
    /**
     * @return list<array{string, string, string, string, mixed, mixed, string}>
     */
    public static function of(ValidationResult $result): array
    {
        return array_map(
            static fn(Violation $violation): array => [
                $violation->code,
                $violation->location,
                $violation->instancePath,
                $violation->specPointer,
                self::plain($violation->expected),
                self::plain($violation->actual),
                $violation->message,
            ],
            $result->violations,
        );
    }

    private static function plain(mixed $value): mixed
    {
        return is_object($value) || is_array($value)
            ? json_decode(json_encode($value, JSON_THROW_ON_ERROR), associative: true, flags: JSON_THROW_ON_ERROR)
            : $value;
    }
}
