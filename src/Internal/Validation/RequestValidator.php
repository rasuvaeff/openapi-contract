<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Validation;

use Psr\Http\Message\RequestInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaDialect;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaValidator;
use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterCodec;
use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterKind;
use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterStyle;
use Rasuvaeff\OpenApiContract\InvalidContract;
use Rasuvaeff\OpenApiContract\MatchedOperation;
use Rasuvaeff\OpenApiContract\ValidationResult;
use Rasuvaeff\OpenApiContract\Violation;

/**
 * @psalm-import-type CompiledParameter from \Rasuvaeff\OpenApiContract\Operation
 *
 * @internal
 */
final readonly class RequestValidator
{
    use MessageReading;

    public function __construct(
        private SchemaValidator $schemas = new SchemaValidator(),
        private ParameterCodec $parameters = new ParameterCodec(),
        private SchemaValueDecoder $values = new SchemaValueDecoder(),
        private FormUrlencodedBodyDecoder $forms = new FormUrlencodedBodyDecoder(),
        private MultipartBodyDecoder $multipart = new MultipartBodyDecoder(),
    ) {}

    public function validate(
        MatchedOperation $matched,
        RequestInterface $request,
        SchemaDialect $dialect,
    ): ValidationResult {
        $violations = [];
        foreach ($matched->operation->parameters as $parameter) {
            $wire = $this->parameterWire($parameter, $matched, $request);
            // Compiled at the declaration, where a Path Item's parameters and
            // an Operation's are still distinguishable.
            $pointer = $parameter['specPointer'];
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
                $value = $this->values->coerce($parsed, $parameter['schema']);
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
            } catch (InvalidContract $exception) {
                // A document this package cannot support is a contract error,
                // not something the request did wrong; `InvalidContract`
                // extends `InvalidArgumentException`, so without this it would
                // be reported as a deserialization violation.
                throw $exception;
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
     * @param CompiledParameter $parameter
     */
    private function parameterWire(array $parameter, MatchedOperation $matched, RequestInterface $request): ?string
    {
        return match ($parameter['in']) {
            'path' => $matched->pathParameters[$parameter['name']] ?? null,
            'header' => $this->headerWire($parameter, $request),
            'query' => $this->queryWire($parameter, $matched, $request->getUri()->getQuery()),
            'cookie' => $this->cookieWire($parameter, $matched, $request->getHeaderLine('Cookie')),
        };
    }

    /**
     * @param CompiledParameter $parameter
     */
    private function headerWire(array $parameter, RequestInterface $request): ?string
    {
        if (!$request->hasHeader($parameter['name'])) {
            return null;
        }
        $wire = $request->getHeaderLine($parameter['name']);
        if ($this->kind($parameter['schema']) === ParameterKind::Scalar) {
            return $wire;
        }

        return $this->parameters->withoutHeaderWhitespace($wire);
    }

    /**
     * @param CompiledParameter $parameter
     */
    private function queryWire(array $parameter, MatchedOperation $matched, string $query): ?string
    {
        if ($query === '') {
            return null;
        }
        if ($parameter['style'] === 'deepObject') {
            return $this->filterPairs($query, static fn(string $key): bool => str_starts_with($key, $parameter['name'] . '['));
        }
        if ($parameter['explode'] && $this->kind($parameter['schema']) === ParameterKind::Object) {
            if (($parameter['schema']['additionalProperties'] ?? true) !== false) {
                $foreign = $this->siblingNames($matched, $parameter);

                return $this->filterPairs($query, static fn(string $key): bool => !in_array($key, $foreign, strict: true));
            }

            $properties = $this->propertyNames($parameter['schema']);

            return $this->filterPairs($query, static fn(string $key): bool => in_array($key, $properties, strict: true));
        }

        return $this->filterPairs($query, static fn(string $key): bool => $key === $parameter['name']);
    }

    /**
     * @param CompiledParameter $parameter
     */
    private function cookieWire(array $parameter, MatchedOperation $matched, string $cookie): ?string
    {
        if ($cookie === '') {
            return null;
        }
        $wire = preg_replace('/;\s*/', '&', $cookie);
        if (!is_string($wire)) {
            return null;
        }
        if ($parameter['explode'] && $this->kind($parameter['schema']) === ParameterKind::Object) {
            if (($parameter['schema']['additionalProperties'] ?? true) !== false) {
                $foreign = $this->siblingNames($matched, $parameter);

                return $this->filterPairs($wire, static fn(string $key): bool => !in_array($key, $foreign, strict: true));
            }

            $properties = $this->propertyNames($parameter['schema']);

            return $this->filterPairs($wire, static fn(string $key): bool => in_array($key, $properties, strict: true));
        }

        return $this->filterPairs($wire, static fn(string $key): bool => $key === $parameter['name']);
    }

    /**
     * The names another parameter in the same location declares. An open
     * object cannot tell an undeclared pair from a stray one — that ambiguity
     * is the style's, not ours — but a pair that a sibling parameter declares
     * is certainly not part of this object.
     *
     * @param CompiledParameter $parameter
     * @return list<string>
     */
    private function siblingNames(MatchedOperation $matched, array $parameter): array
    {
        $names = [];
        foreach ($matched->operation->parameters as $sibling) {
            if ($sibling['in'] === $parameter['in'] && $sibling['name'] !== $parameter['name']) {
                $names[] = $sibling['name'];
            }
        }

        return $names;
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

        try {
            $body = $this->bodyContents($request);
        } catch (MessageBodyTooLarge) {
            return [$this->bodyViolation(
                $matched,
                'request.body.too_large',
                sprintf('Request body exceeds %d bytes', Contract::MAX_MESSAGE_BODY_BYTES),
                'body exceeds validation byte budget',
            )];
        }
        if ($body === null) {
            return [$this->bodyViolation(
                $matched,
                'request.body.non_seekable',
                'Request body stream must be seekable for validation',
                'non-seekable body stream',
            )];
        }
        if ($body === '') {
            return $required ? [$this->bodyViolation($matched, 'request.body.missing', 'Required request body is missing')] : [];
        }
        $mediaType = $this->mediaTypeOf($request);
        $definition = $this->mediaDefinition($content, $mediaType);
        if ($definition === null) {
            return [$this->bodyViolation($matched, 'request.body.media_type', sprintf('Request media type "%s" is not declared', $mediaType))];
        }
        if ($this->declaresNothingValid($definition)) {
            return [$this->bodyViolation($matched, 'request.body.schema', 'Request body does not match its schema')];
        }
        if (!MediaType::isJson($mediaType) && $mediaType !== 'application/x-www-form-urlencoded' && !str_starts_with($mediaType, 'multipart/')) {
            return $this->validateOpaqueBody($matched, $mediaType, $body, $definition, $dialect);
        }
        /** @var mixed $schemaValue */
        $schemaValue = $this->constrainingSchema($definition);
        $schema = $this->values->schema($schemaValue);

        try {
            /** @var mixed $encodingValue */
            $encodingValue = $definition['encoding'] ?? [];
            $encoding = is_array($encodingValue) ? $encodingValue : [];
            if (MediaType::isJson($mediaType)) {
                /** @var null|bool|int|float|string|array<array-key, mixed>|\stdClass $value */
                $value = json_decode($body, depth: 64, flags: JSON_THROW_ON_ERROR);
            } elseif ($mediaType === 'application/x-www-form-urlencoded') {
                $value = $this->forms->decode($body, $schema ?? [], $encoding);
            } else {
                $value = $this->multipart->decode($body, $request->getHeaderLine('Content-Type'), $schema ?? [], $encoding);
            }
        } catch (\JsonException) {
            return [$this->bodyViolation($matched, 'request.body.json', 'Request body is not valid JSON')];
        } catch (BodyDecodingFailed $exception) {
            return [$this->bodyViolation($matched, 'request.body.decode', $exception->getMessage())];
        }
        if ($schema !== null && !$this->schemas->isValid($value, $schema, $dialect)) {
            return [$this->bodyViolation($matched, 'request.body.schema', 'Request body does not match its schema', $value)];
        }

        return [];
    }

    /**
     * A declared non-JSON, non-form media type is validated as far as its
     * schema allows: the body is opaque without a schema, the raw payload is
     * the string value of a string-typed schema, and any other schema cannot
     * be evaluated against an undecoded payload.
     *
     * @param array<array-key, mixed> $definition
     * @return list<Violation>
     */
    private function validateOpaqueBody(MatchedOperation $matched, string $mediaType, string $body, array $definition, SchemaDialect $dialect): array
    {
        return match (OpaqueBodyVerdict::of($this->declaredSchema($definition), $body, $this->schemas, $dialect, 'request')) {
            OpaqueBodyVerdict::Opaque, OpaqueBodyVerdict::Valid => [],
            OpaqueBodyVerdict::Unsupported => [$this->bodyViolation($matched, 'request.body.unsupported', sprintf('Request media type "%s" cannot be validated against a non-string schema', $mediaType), $mediaType)],
            OpaqueBodyVerdict::Invalid => [$this->bodyViolation($matched, 'request.body.schema', 'Request body does not match its schema', $body)],
        };
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

    /**
     * The declared schema with the unconstrained boolean `true` folded into
     * "nothing declared". `false` is handled before this, where it belongs:
     * it is a schema that admits no body at all.
     *
     * @param array<array-key, mixed> $definition
     */
    private function constrainingSchema(array $definition): mixed
    {
        /** @var mixed $schema */
        $schema = $definition['schema'] ?? null;

        return $schema === true ? null : $schema;
    }

    /** @param array<string, mixed> $schema */
    private function kind(array $schema): ParameterKind
    {
        return $this->values->kind($schema);
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

    /**
     * @param array<string, mixed> $schema
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

}
