<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Validation;

use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterCodec;
use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterKind;
use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterStyle;

/**
 * @internal
 */
final readonly class MultipartBodyDecoder
{
    private const int MAX_PARTS = 128;
    private const int MAX_PART_HEADERS_BYTES = 16 * 1024;

    public function __construct(
        private SchemaValueDecoder $values = new SchemaValueDecoder(),
        private ParameterCodec $parameters = new ParameterCodec(),
    ) {}

    /**
     * @param array<string, mixed> $schema
     * @param array<array-key, mixed> $encoding
     */
    public function decode(string $body, string $contentType, array $schema, array $encoding = []): object
    {
        if ($this->values->kind($schema) !== ParameterKind::Object) {
            throw new BodyDecodingFailed('Multipart request bodies require an object schema');
        }
        $properties = $this->values->properties($schema);
        /** @var array<string, mixed> $result */
        $result = [];
        foreach ($this->parts($body, $this->boundary($contentType)) as $part) {
            $name = $this->partName($part['headers']);
            $property = $properties[$name] ?? $this->additionalSchema($schema);
            $configuration = $this->encoding($encoding[$name] ?? null, $name);
            $this->requiredHeaders($configuration['headers'], $part['headers'], $name);
            $value = $this->partValue($part['body'], $part['headers'], $property, $configuration['contentType'], $name);

            if ($this->values->kind($property) === ParameterKind::List) {
                /** @var mixed $itemsValue */
                $itemsValue = $property['items'] ?? null;
                $items = $this->values->schema($itemsValue) ?? [];
                // `explode: false` puts the whole array in one part as a
                // comma-separated list; the exploded form repeats the part.
                if (!$configuration['explode'] && is_string($value)) {
                    $value = $this->parameters->parse($name, $value, ParameterStyle::Simple, explode: false, kind: ParameterKind::List);
                }
                $values = is_array($value) && array_is_list($value) ? $value : [$value];
                /** @var list<mixed> $list */
                $list = is_array($result[$name] ?? null) ? $result[$name] : [];
                foreach (array_keys($values) as $index) {
                    $list[] = $this->partItem($values[$index] ?? null, $items);
                }
                $result[$name] = $list;
                continue;
            }
            if (array_key_exists($name, $result)) {
                throw new BodyDecodingFailed(sprintf('Multipart property "%s" occurs more than once', $name));
            }
            $result[$name] = is_string($value) ? $this->values->scalar($value, $property) : $value;
        }

        return (object) $result;
    }

    /**
     * @param array<string, mixed> $schema
     * @return null|bool|int|float|string|array<array-key, mixed>|\stdClass
     */
    private function partItem(mixed $value, array $schema): bool|int|float|string|array|\stdClass|null
    {
        return is_string($value) ? $this->values->scalar($value, $schema) : match (true) {
            is_int($value), is_float($value), is_bool($value), is_array($value), $value instanceof \stdClass, $value === null => $value,
            default => throw new BodyDecodingFailed('Multipart array property contains an unsupported value'),
        };
    }

    private function boundary(string $contentType): string
    {
        if (preg_match('/(?:^|;)\s*boundary=(?:"([^"]+)"|([^;\s]+))/i', $contentType, $match) !== 1) {
            throw new BodyDecodingFailed('Multipart request body has no boundary');
        }
        $boundary = $match[1] !== '' ? $match[1] : $match[2];
        if (strlen($boundary) > 70 || preg_match("/^[0-9A-Za-z'()+_,.\/:=? -]+$/", $boundary) !== 1) {
            throw new BodyDecodingFailed('Multipart request boundary is invalid');
        }

        return $boundary;
    }

    /** @return list<array{headers: array<string, string>, body: string}> */
    private function parts(string $body, string $boundary): array
    {
        $delimiter = '--' . $boundary;
        if (!str_starts_with($body, $delimiter . "\r\n")) {
            throw new BodyDecodingFailed('Multipart request body has invalid boundary framing');
        }
        $segments = explode("\r\n" . $delimiter, substr($body, strlen($delimiter) + 2));
        $closing = array_pop($segments);
        if ($closing !== "--\r\n") {
            throw new BodyDecodingFailed('Multipart request body has an invalid closing boundary');
        }
        if (count($segments) > self::MAX_PARTS) {
            throw new BodyDecodingFailed(sprintf('Multipart request body exceeds %d parts', self::MAX_PARTS));
        }

        $parts = [];
        foreach ($segments as $index => $segment) {
            if ($index > 0 && !str_starts_with($segment, "\r\n")) {
                throw new BodyDecodingFailed('Multipart request part has invalid framing');
            }
            if ($index > 0) {
                $segment = substr($segment, 2);
            }
            $separator = strpos($segment, "\r\n\r\n");
            if ($separator === false) {
                throw new BodyDecodingFailed('Multipart request part has no header terminator');
            }
            $headerBlock = substr($segment, 0, $separator);
            if (strlen($headerBlock) > self::MAX_PART_HEADERS_BYTES) {
                throw new BodyDecodingFailed(sprintf('Multipart request part headers exceed %d bytes', self::MAX_PART_HEADERS_BYTES));
            }
            $parts[] = ['headers' => $this->headers($headerBlock), 'body' => substr($segment, $separator + 4)];
        }

        return $parts;
    }

    /** @return array<string, string> */
    private function headers(string $block): array
    {
        $headers = [];
        foreach (explode("\r\n", $block) as $line) {
            if (preg_match('/^([!#$%&\'*+.^_`|~0-9A-Za-z-]+):[ \t]*(.*)$/', $line, $match) !== 1) {
                throw new BodyDecodingFailed('Multipart request part contains an invalid header');
            }
            $name = strtolower($match[1]);
            if (array_key_exists($name, $headers)) {
                throw new BodyDecodingFailed(sprintf('Multipart request part repeats header "%s"', $name));
            }
            $headers[$name] = trim($match[2]);
        }

        return $headers;
    }

    /** @param array<string, string> $headers */
    private function partName(array $headers): string
    {
        $disposition = $headers['content-disposition'] ?? '';
        if (preg_match('/^form-data(?:\s*;.*)?$/i', $disposition) !== 1
            || preg_match('/(?:^|;)\s*name=(?:"([^"]+)"|([^;\s]+))/i', $disposition, $match) !== 1) {
            throw new BodyDecodingFailed('Multipart request part requires Content-Disposition form-data with a name');
        }
        $name = $match[1] !== '' ? $match[1] : $match[2];
        if ($name === '') {
            throw new BodyDecodingFailed('Multipart request part name must not be empty');
        }

        return $name;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $schema
     */
    private function partValue(string $body, array $headers, array $schema, ?string $declaredContentType, string $name): string|int|float|bool|array|\stdClass|null
    {
        $actual = MediaType::normalize($headers['content-type'] ?? 'text/plain');
        $expected = $declaredContentType ?? $this->defaultContentType($schema);
        $matches = array_filter(array_map(trim(...), explode(',', strtolower($expected))), fn(string $type): bool => MediaType::matches($type, $actual));
        if ($matches === []) {
            throw new BodyDecodingFailed(sprintf('Multipart property "%s" has content type "%s", expected "%s"', $name, $actual, $expected));
        }
        if (MediaType::isJson($actual)) {
            try {
                /** @var null|bool|int|float|string|array<array-key, mixed>|\stdClass $decoded */
                $decoded = json_decode($body, depth: 64, flags: JSON_THROW_ON_ERROR);

                return $decoded;
            } catch (\JsonException $exception) {
                throw new BodyDecodingFailed(sprintf('Multipart property "%s" is not valid JSON', $name), $exception->getCode(), previous: $exception);
            }
        }

        return $body;
    }

    /**
     * OAS 3 default part Content-Type: `text/plain` for primitives,
     * `application/octet-stream` for binary strings, `application/json` for
     * objects, and for arrays the default of the item type.
     *
     * @param array<string, mixed> $schema
     */
    private function defaultContentType(array $schema): string
    {
        if ($this->values->kind($schema) === ParameterKind::List) {
            /** @var mixed $itemsValue */
            $itemsValue = $schema['items'] ?? null;
            $schema = $this->values->schema($itemsValue) ?? [];
        }
        if ($this->values->kind($schema) !== ParameterKind::Scalar) {
            return 'application/json';
        }

        return ($schema['format'] ?? null) === 'binary' ? 'application/octet-stream' : 'text/plain';
    }

    /**
     * @param array<array-key, mixed> $headers
     * @param array<string, string> $actual
     */
    private function requiredHeaders(array $headers, array $actual, string $property): void
    {
        foreach (array_keys($headers) as $name) {
            if (!is_string($name)) {
                continue;
            }
            /** @var mixed $required */
            $required = is_array($headers[$name]) ? ($headers[$name]['required'] ?? false) : false;
            if ($required === true && !array_key_exists(strtolower($name), $actual)) {
                throw new BodyDecodingFailed(sprintf('Multipart property "%s" requires header "%s"', $property, $name));
            }
        }
    }

    /** @return array{contentType: ?string, headers: array<array-key, mixed>, explode: bool} */
    private function encoding(mixed $value, string $name): array
    {
        if ($value === null) {
            return ['contentType' => null, 'headers' => [], 'explode' => true];
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new BodyDecodingFailed(sprintf('Encoding for multipart property "%s" must be an object', $name));
        }
        /** @var mixed $style */
        $style = $value['style'] ?? 'form';
        if ($style !== 'form') {
            throw new BodyDecodingFailed(sprintf('Encoding style for multipart property "%s" must be form', $name));
        }
        /** @var mixed $contentTypeValue */
        $contentTypeValue = $value['contentType'] ?? null;
        if ($contentTypeValue !== null && (!is_string($contentTypeValue) || $contentTypeValue === '')) {
            throw new BodyDecodingFailed(sprintf('Encoding contentType for multipart property "%s" must be a non-empty string', $name));
        }
        $contentType = is_string($contentTypeValue) ? $contentTypeValue : null;
        /** @var mixed $headersValue */
        $headersValue = $value['headers'] ?? [];
        if (!is_array($headersValue)) {
            throw new BodyDecodingFailed(sprintf('Encoding headers for multipart property "%s" must be an object', $name));
        }
        /** @var mixed $explode */
        $explode = $value['explode'] ?? true;
        if (!is_bool($explode)) {
            throw new BodyDecodingFailed(sprintf('Encoding explode for multipart property "%s" must be a boolean', $name));
        }

        return ['contentType' => $contentType, 'headers' => $headersValue, 'explode' => $explode];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function additionalSchema(array $schema): array
    {
        /** @var mixed $additional */
        $additional = $schema['additionalProperties'] ?? null;

        return is_bool($additional) ? [] : ($this->values->schema($additional) ?? []);
    }
}
