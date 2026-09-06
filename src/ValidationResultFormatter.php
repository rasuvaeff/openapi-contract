<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

/**
 * Deterministic, bounded text rendering of structured validation results.
 *
 * @api
 */
final readonly class ValidationResultFormatter
{
    private const int MAX_FIELD_BYTES = 256;
    private const int MAX_VALUE_BYTES = 512;
    private const int MAX_VALUE_DEPTH = 4;
    private const int MAX_VALUE_ITEMS = 16;
    private const string REDACTED = '[redacted]';

    public function format(ValidationResult $result): string
    {
        if ($result->isValid()) {
            return 'OpenAPI contract validation passed';
        }

        $lines = [sprintf(
            'OpenAPI contract validation failed with %d violation(s)',
            count($result->violations),
        )];
        foreach ($result->violations as $index => $violation) {
            $lines[] = sprintf('%d. code: %s', $index + 1, $this->field($violation->code));
            $lines[] = '   operation: ' . $this->field($violation->operation);
            $lines[] = '   location: ' . $this->field($violation->location);
            $lines[] = '   instancePath: ' . $this->field($violation->instancePath);
            $lines[] = '   specPointer: ' . $this->field($violation->specPointer);
            $lines[] = '   expected: ' . $this->value($violation->expected);
            $lines[] = '   actual: ' . ($this->redactsActual($violation) ? $this->value(self::REDACTED) : $this->value($violation->actual, maskNames: true));
            $lines[] = '   message: ' . $this->field($violation->message);
        }

        return implode("\n", $lines);
    }

    private function field(string $value): string
    {
        $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);

        return $this->truncate(is_string($encoded) ? $encoded : '"[unrenderable]"', self::MAX_FIELD_BYTES);
    }

    private function isSensitiveName(string $name): bool
    {
        return preg_match('/(?:authorization|api[-_]?key|token|secret|password|cookie)/i', $name) === 1;
    }

    /**
     * @param bool $maskNames replace the value of every member whose name
     *        matches the credential pattern — see {@see redactsActual()}
     */
    private function value(mixed $value, bool $maskNames = false): string
    {
        $encoded = json_encode(
            $this->normalize($value, depth: 0, maskNames: $maskNames),
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );

        return $this->truncate(is_string($encoded) ? $encoded : '"[unrenderable]"', self::MAX_VALUE_BYTES);
    }

    /** @return null|bool|int|float|string|array<array-key, mixed> */
    private function normalize(mixed $value, int $depth, bool $maskNames = false): bool|int|float|string|array|null
    {
        if ($depth >= self::MAX_VALUE_DEPTH && (is_array($value) || is_object($value))) {
            return '[depth limit]';
        }
        if ($value instanceof \stdClass) {
            return $this->normalize(get_object_vars($value), $depth, $maskNames);
        }
        if (is_object($value)) {
            return sprintf('[object %s]', $value::class);
        }
        if (is_resource($value)) {
            return '[resource]';
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return '[unrenderable]';
        }

        /** @var array<array-key, mixed> $value */
        /** @var array<array-key, mixed> $normalized */
        $normalized = [];
        foreach (array_keys($value) as $key) {
            if (count($normalized) >= self::MAX_VALUE_ITEMS) {
                $normalized[] = '[item limit]';
                break;
            }
            /** @var mixed $item */
            $item = $value[$key];
            $normalizedItem = $maskNames && $this->isSensitiveName((string) $key)
                ? self::REDACTED
                : $this->normalize($item, $depth + 1, $maskNames);
            $normalized[$key] = $normalizedItem;
        }
        if (!array_is_list($normalized)) {
            ksort($normalized, SORT_STRING);
        }

        return $normalized;
    }

    /**
     * A body is user data of unknown shape: a schema failure anywhere in it
     * can put credentials into the rendered diagnostic, its member names are
     * the application's rather than the document's, and the instance path is
     * `$` for a whole-body violation, so there is nothing to inspect. Bodies
     * stay redacted wholesale rather than guessing which field was sensitive.
     *
     * Everything else is named — by the instance path when the value is a
     * scalar, and by its own keys when it is a container, both of which the
     * document declares. Redacting those locations outright, as this did, left
     * `expected` printed in full, schema and all, beside an `actual` that
     * never said anything: not a safe default but an unreadable diagnostic.
     * The name pattern decides instead, and {@see maskSensitiveKeys()} applies
     * it to the members the instance path does not name.
     */
    private function redactsActual(Violation $violation): bool
    {
        if ($violation->location === 'body') {
            return true;
        }

        return $this->isSensitiveName($violation->instancePath);
    }

    /**
     * The budget is a byte budget — it exists to bound the message — and the
     * value handed here is always `json_encode` output, which escapes every
     * non-ASCII character as `\uXXXX`. Cutting it therefore cannot split a
     * UTF-8 sequence, and the rendered message is valid UTF-8 whatever the
     * document and the payload contained. What a cut *can* land inside is one
     * of those escapes, which leaves `\u04` where a character was meant, so
     * a partial escape at the end is dropped rather than shown.
     */
    private function truncate(string $value, int $bytes): string
    {
        if (strlen($value) <= $bytes) {
            return $value;
        }
        $cut = substr($value, 0, $bytes - 3);

        return (preg_replace('/(?<!\\\\)\\\\(?:u[0-9a-fA-F]{0,3})?$/', '', $cut) ?? $cut) . '...';
    }
}
