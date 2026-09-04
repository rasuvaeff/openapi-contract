<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Validation;

/**
 * Media type normalization and matching, shared by everything that has to
 * decide whether a declared `content` key covers what a message actually
 * carries: the request and response validators through {@see MessageReading},
 * and {@see MultipartBodyDecoder} for the Content-Type of a single part.
 *
 * @internal
 */
final readonly class MediaType
{
    /**
     * The bare type/subtype of a Content-Type header or a `content` key,
     * lowercased and stripped of its parameters.
     */
    public static function normalize(string $value): string
    {
        return strtolower(trim(explode(';', $value, 2)[0]));
    }

    /**
     * Whether a declared `content` key covers an already-normalized actual
     * media type. The declaration is normalized here, so a key written with
     * parameters or in mixed case still matches.
     */
    public static function matches(string $declared, string $actual): bool
    {
        $declared = self::normalize($declared);
        if ($declared === $actual || $declared === '*/*') {
            return true;
        }
        [$declaredType, $declaredSubtype] = array_pad(explode('/', $declared, 2), 2, '');
        [$actualType, $actualSubtype] = array_pad(explode('/', $actual, 2), 2, '');
        if ($declaredType !== $actualType) {
            return false;
        }

        return $declaredSubtype === '*' || ($declaredSubtype === '*+json' && str_ends_with($actualSubtype, '+json'));
    }

    /**
     * Whether a normalized media type carries a JSON payload: the JSON media
     * type itself, or any of the `+json` structured syntax suffixes.
     */
    public static function isJson(string $mediaType): bool
    {
        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }
}
