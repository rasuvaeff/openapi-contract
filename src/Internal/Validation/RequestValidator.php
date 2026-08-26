<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Validation;

use Psr\Http\Message\RequestInterface;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaDialect;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaValidator;
use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterCodec;
use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterKind;
use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterStyle;
use Rasuvaeff\OpenApiContract\MatchedOperation;
use Rasuvaeff\OpenApiContract\ValidationResult;
use Rasuvaeff\OpenApiContract\Violation;

/**
 * @internal
 */
final readonly class RequestValidator
{
    public function __construct(
        private SchemaValidator $schemas = new SchemaValidator(),
        private ParameterCodec $parameters = new ParameterCodec(),
    ) {}

    public function validate(
        MatchedOperation $matched,
        RequestInterface $request,
        SchemaDialect $dialect,
    ): ValidationResult {
        $violations = [];
        foreach ($matched->operation->parameters as $index => $parameter) {
            $wire = $this->parameterWire($parameter, $matched, $request);
            $pointer = sprintf('/paths/%s/%s/parameters/%d', $this->escape($matched->operation->path), strtolower($matched->operation->method), $index);
            if ($wire === null) {
                if ($parameter['required']) {
                    $violations[] = new Violation(
                        code: 'request.parameter.missing',
                        operation: $matched->operation->key,
                        location: $parameter['in'],
                        instancePath: $parameter['name'],
                        specPointer: $pointer,
                        expected: 'required parameter',
                        actual: null,
                        message: sprintf('Required %s parameter "%s" is missing', $parameter['in'], $parameter['name']),
                    );
                }
                continue;
            }

            try {
                $parsed = $this->parameters->parse(
                    name: $parameter['name'],
                    wire: $wire,
                    style: $this->style($parameter['style']),
                    explode: $parameter['explode'],
                    kind: $this->kind($parameter['schema']),
                );
                /** @var mixed $value */
                $value = $this->coerce($parsed, $parameter['schema']);
                if (!$this->schemas->isValid($value, $parameter['schema'], $dialect)) {
                    $violations[] = new Violation(
                        code: 'request.parameter.schema',
                        operation: $matched->operation->key,
                        location: $parameter['in'],
                        instancePath: $parameter['name'],
                        specPointer: $pointer . '/schema',
                        expected: $parameter['schema'],
                        actual: $value,
                        message: sprintf('%s parameter "%s" does not match its schema', ucfirst($parameter['in']), $parameter['name']),
                    );
                }
            } catch (\InvalidArgumentException) {
                $violations[] = new Violation(
                    code: 'request.parameter.serialization',
                    operation: $matched->operation->key,
                    location: $parameter['in'],
                    instancePath: $parameter['name'],
                    specPointer: $pointer,
                    expected: $parameter['style'],
                    actual: $wire,
                    message: sprintf('%s parameter "%s" cannot be deserialized', ucfirst($parameter['in']), $parameter['name']),
                );
            }
        }

        return new ValidationResult([...$violations, ...$this->validateBody($matched, $request, $dialect)]);
    }

    /**
     * @param array{name: non-empty-string, in: 'path'|'query'|'header'|'cookie', required: bool, style: string, explode: bool, allowReserved: bool, schema: array<string, mixed>} $parameter
     */
    private function parameterWire(array $parameter, MatchedOperation $matched, RequestInterface $request): ?string
    {
        return match ($parameter['in']) {
            'path' => $matched->pathParameters[$parameter['name']] ?? null,
            'header' => $request->hasHeader($parameter['name']) ? $request->getHeaderLine($parameter['name']) : null,
            'query' => $this->queryWire($parameter, $request->getUri()->getQuery()),
            'cookie' => $this->cookieWire($parameter, $request->getHeaderLine('Cookie')),
        };
    }

    /**
     * @param array{name: non-empty-string, in: 'path'|'query'|'header'|'cookie', required: bool, style: string, explode: bool, allowReserved: bool, schema: array<string, mixed>} $parameter
     */
    private function queryWire(array $parameter, string $query): ?string
    {
        if ($query === '') {
            return null;
        }
        if ($parameter['style'] === 'deepObject') {
            return $this->filterPairs($query, static fn(string $key): bool => str_starts_with($key, $parameter['name'] . '['));
        }
        if ($parameter['explode'] && $this->kind($parameter['schema']) === ParameterKind::Object) {
            $properties = $this->propertyNames($parameter['schema']);

            return $this->filterPairs($query, static fn(string $key): bool => in_array($key, $properties, strict: true));
        }

        return $this->filterPairs($query, static fn(string $key): bool => $key === $parameter['name']);
    }

    /**
     * @param array{name: non-empty-string, in: 'path'|'query'|'header'|'cookie', required: bool, style: string, explode: bool, allowReserved: bool, schema: array<string, mixed>} $parameter
     */
    private function cookieWire(array $parameter, string $cookie): ?string
    {
        if ($cookie === '') {
            return null;
        }
        $wire = preg_replace('/;\s*/', '&', $cookie);
        if (!is_string($wire)) {
            return null;
        }
        if ($parameter['explode'] && $this->kind($parameter['schema']) === ParameterKind::Object) {
            $properties = $this->propertyNames($parameter['schema']);

            return $this->filterPairs($wire, static fn(string $key): bool => in_array($key, $properties, strict: true));
        }

        return $this->filterPairs($wire, static fn(string $key): bool => $key === $parameter['name']);
    }

    /** @param callable(string): bool $accept */
    private function filterPairs(string $wire, callable $accept): ?string
    {
        $accepted = [];
        foreach (explode('&', $wire) as $pair) {
            $key = rawurldecode(explode('=', $pair, 2)[0]);
            if ($accept($key)) {
                $accepted[] = $pair;
            }
        }

        return $accepted === [] ? null : implode('&', $accepted);
    }

    /**
     * @return list<Violation>
     */
    private function validateBody(MatchedOperation $matched, RequestInterface $request, SchemaDialect $dialect): array
    {
        $requestBody = $matched->operation->requestBody;
        if ($requestBody === []) {
            return [];
        }
        $required = ($requestBody['required'] ?? false) === true;
        $content = $requestBody['content'] ?? null;
        if (!is_array($content)) {
            return [$this->bodyViolation($matched, 'request.body.media_type', 'Request body has no supported content definition')];
        }
        $body = $this->bodyContents($request);
        if ($body === '') {
            return $required ? [$this->bodyViolation($matched, 'request.body.missing', 'Required request body is missing')] : [];
        }
        $mediaType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
        $definition = $this->mediaDefinition($content, $mediaType);
        if ($definition === null) {
            return [$this->bodyViolation($matched, 'request.body.media_type', sprintf('Request media type "%s" is not declared', $mediaType))];
        }
        if (!$this->isJsonMediaType($mediaType)) {
            return [$this->bodyViolation($matched, 'request.body.media_type', sprintf('Request media type "%s" is not supported', $mediaType))];
        }

        try {
            $value = $this->decodeJson($body);
        } catch (\JsonException) {
            return [$this->bodyViolation($matched, 'request.body.json', 'Request body is not valid JSON')];
        }
        /** @var mixed $schemaValue */
        $schemaValue = $definition['schema'] ?? null;
        $schema = $this->schema($schemaValue);
        if ($schema !== null && !$this->schemas->isValid($value, $schema, $dialect)) {
            return [$this->bodyViolation($matched, 'request.body.schema', 'Request body does not match its schema', $value)];
        }

        return [];
    }

    private function bodyContents(RequestInterface $request): string
    {
        $stream = $request->getBody();
        if (!$stream->isSeekable()) {
            return $stream->getContents();
        }
        $position = $stream->tell();
        $stream->rewind();
        $contents = $stream->getContents();
        $stream->seek($position);

        return $contents;
    }

    /** @param array<array-key, mixed> $content
     * @return array<array-key, mixed>|null
     */
    private function mediaDefinition(array $content, string $mediaType): ?array
    {
        foreach ($content as $declared => $definition) {
            if (!is_string($declared) || !is_array($definition)) {
                continue;
            }
            if (strtolower($declared) === $mediaType || ($declared === 'application/*+json' && str_ends_with($mediaType, '+json'))) {
                return $definition;
            }
        }

        return null;
    }

    private function isJsonMediaType(string $mediaType): bool
    {
        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }

    /** @return null|bool|int|float|string|array<array-key, mixed>|\stdClass */
    private function decodeJson(string $body): bool|int|float|string|array|\stdClass|null
    {
        /** @var null|bool|int|float|string|array<array-key, mixed>|\stdClass */
        return json_decode($body, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed>|null */
    private function schema(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('Schema must be an object');
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Schema keys must be strings');
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private function bodyViolation(MatchedOperation $matched, string $code, string $message, mixed $actual = null): Violation
    {
        return new Violation(
            code: $code,
            operation: $matched->operation->key,
            location: 'body',
            instancePath: '$',
            specPointer: sprintf('/paths/%s/%s/requestBody', $this->escape($matched->operation->path), strtolower($matched->operation->method)),
            expected: $matched->operation->requestBody,
            actual: $actual,
            message: $message,
        );
    }

    /** @param array<string, mixed> $schema */
    private function kind(array $schema): ParameterKind
    {
        /** @var mixed $type */
        $type = $schema['type'] ?? 'string';
        if ($type === 'array') {
            return ParameterKind::List;
        }
        if ($type === 'object') {
            return ParameterKind::Object;
        }

        return ParameterKind::Scalar;
    }

    private function style(string $style): ParameterStyle
    {
        return match ($style) {
            'simple' => ParameterStyle::Simple,
            'label' => ParameterStyle::Label,
            'matrix' => ParameterStyle::Matrix,
            'form' => ParameterStyle::Form,
            'spaceDelimited' => ParameterStyle::SpaceDelimited,
            'pipeDelimited' => ParameterStyle::PipeDelimited,
            'deepObject' => ParameterStyle::DeepObject,
            default => throw new \LogicException(sprintf('Unsupported compiled parameter style "%s"', $style)),
        };
    }

    /** @param array<string, mixed> $schema
     * @return list<string>
     */
    private function propertyNames(array $schema): array
    {
        $properties = $schema['properties'] ?? null;
        if (!is_array($properties)) {
            return [];
        }

        $names = [];
        foreach (array_keys($properties) as $name) {
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param string|list<string>|array<string, string> $value
     * @param array<string, mixed> $schema
     */
    private function coerce(string|array $value, array $schema): string|int|float|bool|array|\stdClass|null
    {
        if (is_string($value)) {
            return self::coerceScalar($value, $schema);
        }
        if (array_is_list($value)) {
            /** @var mixed $itemsValue */
            $itemsValue = $schema['items'] ?? null;
            $items = $this->schema($itemsValue) ?? [];

            return array_map(static fn(string $item): mixed => self::coerceScalar($item, $items), $value);
        }
        /** @var mixed $propertiesValue */
        $propertiesValue = $schema['properties'] ?? null;
        $properties = is_array($propertiesValue) ? $propertiesValue : [];
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($item)) {
                continue;
            }
            /** @var mixed $propertyValue */
            $propertyValue = $properties[$key] ?? null;
            $property = $this->schema($propertyValue) ?? [];
            $result[$key] = self::coerceScalar($item, $property);
        }

        /** @var \stdClass $object */
        $object = (object) $result;

        return $object;
    }

    /** @param array<string, mixed> $schema */
    private static function coerceScalar(string $value, array $schema): string|int|float|bool|null
    {
        /** @var mixed $type */
        $type = $schema['type'] ?? 'string';
        $types = is_array($type) ? $type : [$type];
        if (in_array('null', $types, strict: true) && $value === 'null') {
            return null;
        }
        if (in_array('integer', $types, strict: true) && preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value) === 1) {
            return (int) $value;
        }
        if (in_array('number', $types, strict: true) && is_numeric($value)) {
            return (float) $value;
        }
        if (in_array('boolean', $types, strict: true) && ($value === 'true' || $value === 'false')) {
            return $value === 'true';
        }

        return $value;
    }

    private function escape(string $value): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $value);
    }
}
