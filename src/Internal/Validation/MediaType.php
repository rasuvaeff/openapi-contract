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
        return self::specificity($declared, $actual) !== null;
    }

    /**
     * How specifically a declared `content` key covers an actual media type,
     * or `null` when it does not cover it at all; a larger answer is the more
     * specific declaration.
     *
     * A `content` map is a map, and OpenAPI gives no meaning to the order its
     * keys were written in. Taking the first key that matched meant a `*\/*`
     * entry above an exact one decided the body's schema, and moving the two
     * lines changed what the document said.
     */
    public static function specificity(string $declared, string $actual): ?int
    {
        $declared = self::normalize($declared);
        if ($declared === $actual) {
            return 3;
        }
        if ($declared === '*/*') {
            return 0;
        }
        [$declaredType, $declaredSubtype] = array_pad(explode('/', $declared, 2), 2, '');
        [$actualType, $actualSubtype] = array_pad(explode('/', $actual, 2), 2, '');
        if ($declaredType !== $actualType) {
            return null;
        }
        if ($declaredSubtype === '*+json' && str_ends_with($actualSubtype, '+json')) {
            return 2;
        }

        return $declaredSubtype === '*' ? 1 : null;
    }

    /**
     * Whether a media type carries a JSON payload: the JSON media type
     * itself, or any of the `+json` structured syntax suffixes. Accepts an
     * unnormalized value, so the answer cannot depend on which caller
     * remembered to normalize first — the twin helper in
     * `property-testing-openapi` reads the same way.
     */
    public static function isJson(string $value): bool
    {
        $normalized = self::normalize($value);

        return $normalized === 'application/json' || str_ends_with($normalized, '+json');
    }
}
