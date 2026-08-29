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
            $lines[] = '   actual: ' . ($this->redactsActual($violation) ? '"[redacted]"' : $this->value($violation->actual));
            $lines[] = '   message: ' . $this->field($violation->message);
        }

        return implode("\n", $lines);
    }

    private function field(string $value): string
    {
        $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);

        return $this->truncate(is_string($encoded) ? $encoded : '"[unrenderable]"', self::MAX_FIELD_BYTES);
    }

    private function value(mixed $value): string
    {
        $encoded = json_encode(
            $this->normalize($value, depth: 0),
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );

        return $this->truncate(is_string($encoded) ? $encoded : '"[unrenderable]"', self::MAX_VALUE_BYTES);
    }

    /** @return null|bool|int|float|string|array<array-key, mixed> */
    private function normalize(mixed $value, int $depth): bool|int|float|string|array|null
    {
        if ($depth >= self::MAX_VALUE_DEPTH && (is_array($value) || is_object($value))) {
            return '[depth limit]';
        }
        if ($value instanceof \stdClass) {
            return $this->normalize(get_object_vars($value), $depth);
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
            $normalizedItem = $this->normalize($item, $depth + 1);
            $normalized[$key] = $normalizedItem;
        }
        if (!array_is_list($normalized)) {
            ksort($normalized, SORT_STRING);
        }

        return $normalized;
    }

    private function redactsActual(Violation $violation): bool
    {
        if (in_array($violation->location, ['header', 'cookie', 'query'], strict: true)) {
            return true;
        }

        return preg_match('/(?:authorization|api[-_]?key|token|secret|password|cookie)/i', $violation->instancePath) === 1;
    }

    private function truncate(string $value, int $bytes): string
    {
        if (strlen($value) <= $bytes) {
            return $value;
        }

        return substr($value, 0, $bytes - 3) . '...';
    }
}
