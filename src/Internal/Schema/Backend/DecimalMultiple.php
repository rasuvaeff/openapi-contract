<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Schema\Backend;

/**
 * Whether a number is a multiple of a divisor, judged on the decimals the two
 * numbers *are* rather than on the doubles PHP holds them in.
 *
 * JSON Schema says a numeric instance is valid when "division by this
 * keyword's value results in an integer" — a statement about the numbers in
 * the document, which are decimals. The backend judged it on the parsed
 * doubles instead, two ways: in floating point with a `1e-14` tolerance,
 * which from about `64` upward is less than one ulp, so `64.1` — one ulp from
 * `641 × 0.1` — was rejected for `multipleOf: 0.1`; or, with `ext-bcmath`
 * loaded, on the double's own expansion to fourteen decimals, which is
 * `123.40000000000001` for `123.4` and was rejected the same way. Two
 * environments, two verdicts, neither the specification's (#151).
 *
 * Both numbers are taken back to the shortest decimal that reads as the same
 * double (`serialize_precision = -1`, which is how `json_encode` writes them
 * and how the document and the request spelled them), scaled to integers over
 * a common power of ten, and divided exactly — a divisor never carries more
 * than the seventeen significant digits a double can spell, so the remainder
 * is taken digit by digit in native integers however wide the value. No
 * optional extension takes part, so the verdict is the same on every machine.
 *
 * @internal
 */
final readonly class DecimalMultiple
{
    public static function holds(int|float $value, int|float $divisor): bool
    {
        if (is_int($value) && is_int($divisor)) {
            return $divisor !== 0 && $value % $divisor === 0;
        }
        if (is_float($value) && !is_finite($value) || is_float($divisor) && !is_finite($divisor) || (float) $divisor === 0.0) {
            return false;
        }
        if ((float) $value === 0.0) {
            return true;
        }
        [$valueDigits, $valueExponent] = self::decimal($value);
        [$divisorDigits, $divisorExponent] = self::decimal($divisor);
        $exponent = min($valueExponent, $divisorExponent);
        $scaledValue = $valueDigits . str_repeat('0', $valueExponent - $exponent);
        $scaledDivisor = $divisorDigits . str_repeat('0', $divisorExponent - $exponent);

        // Dividing both by the same power of ten changes nothing about
        // divisibility; what is left of the divisor's trailing zeros after
        // that cannot divide a value that has none.
        $shared = min(strlen($scaledValue) - strlen(rtrim($scaledValue, '0')), strlen($scaledDivisor) - strlen(rtrim($scaledDivisor, '0')));
        $scaledValue = substr($scaledValue, 0, strlen($scaledValue) - $shared);
        $scaledDivisor = substr($scaledDivisor, 0, strlen($scaledDivisor) - $shared);
        if (str_ends_with($scaledDivisor, '0')) {
            return false;
        }

        // The divisor carries at most the seventeen significant digits a
        // double can spell, so it is a native integer; the value can be as
        // long as its exponent made it and is reduced digit by digit.
        $modulus = (int) $scaledDivisor;
        $remainder = 0;
        foreach (str_split($scaledValue) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % $modulus;
        }

        return $remainder === 0;
    }

    /**
     * The number as `[unsigned digits, exponent]`, meaning `digits × 10^exponent`,
     * from its shortest round-trip spelling.
     *
     * @return array{non-empty-string, int}
     */
    private static function decimal(int|float $number): array
    {
        $spelled = json_encode(abs($number), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $exponent = 0;
        $mantissa = $spelled;
        $exponentAt = strpbrk($spelled, 'eE');
        if ($exponentAt !== false) {
            $mantissa = substr($spelled, 0, -strlen($exponentAt));
            $exponent = (int) substr($exponentAt, 1);
        }
        $point = strpos($mantissa, '.');
        if ($point !== false) {
            $exponent -= strlen($mantissa) - $point - 1;
            $mantissa = substr($mantissa, 0, $point) . substr($mantissa, $point + 1);
        }
        $digits = ltrim($mantissa, '0');

        return [$digits === '' ? '0' : $digits, $exponent];
    }
}
