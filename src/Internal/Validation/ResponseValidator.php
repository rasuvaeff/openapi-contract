<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Validation;

use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Internal\Response\ResponseSelector;
use Rasuvaeff\OpenApiContract\Internal\Response\SelectedResponse;
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
 * @internal
 */
final readonly class ResponseValidator
{
    use MessageReading;

    private ResponseSelector $selector;

    private ParameterCodec $parameters;

    private SchemaValueDecoder $values;

    /** @see RequestValidator::__construct() for why only this one is injected. */
    public function __construct(private SchemaValidator $schemas = new SchemaValidator())
    {
        $this->selector = new ResponseSelector();
        // Headers are the only thing this validator deserializes, and a header
        // field value is not percent-encoded.
        $this->parameters = new ParameterCodec(percentEncoded: false);
        $this->values = new SchemaValueDecoder();
    }

    public function validate(
        MatchedOperation $matched,
        ResponseInterface $response,
        SchemaDialect $dialect,
    ): ValidationResult {
        $status = $response->getStatusCode();
        if ($status < 100 || $status > 599) {
            // The contract is fine and the response is not: a PSR-7
            // implementation that does not police its status code is a bad
            // message, which is what a violation is for.
            return new ValidationResult([new Violation(
                code: 'response.status.invalid',
                operation: $matched->operation->key,
                location: 'status',
                instancePath: '$',
                specPointer: sprintf('/paths/%s/%s/responses', $this->escape($matched->operation->path), strtolower($matched->operation->method)),
                expected: 'HTTP status between 100 and 599',
                actual: $status,
                message: sprintf('Response status %d is not a valid HTTP status code', $status),
            )]);
        }
        $selected = $this->selector->select($matched->operation->responses, $status);
        if (!$selected instanceof SelectedResponse) {
            return new ValidationResult([new Violation(
                code: 'response.status.mismatch',
                operation: $matched->operation->key,
                location: 'status',
                instancePath: '$',
                specPointer: sprintf('/paths/%s/%s/responses', $this->escape($matched->operation->path), strtolower($matched->operation->method)),
                expected: array_keys($matched->operation->responses),
                actual: $response->getStatusCode(),
                message: sprintf('Response status %d is not declared', $response->getStatusCode()),
            )]);
        }

        $definition = $selected->definition;
        $violations = [];
        $basePointer = sprintf(
            '/paths/%s/%s/responses/%s',
            $this->escape($matched->operation->path),
            strtolower($matched->operation->method),
            $this->escape($selected->key),
        );

        /** @var mixed $headersValue */
        $headersValue = $definition['headers'] ?? [];
        $headers = is_array($headersValue) ? $headersValue : [];
        foreach ($headers as $name => $header) {
            if (!is_string($name) || !is_array($header) || strcasecmp($name, 'content-type') === 0) {
                continue;
            }
            $pointer = $basePointer . '/headers/' . $this->escape($name);
            if (!$response->hasHeader($name)) {
                if (($header['required'] ?? false) === true) {
                    $violations[] = new Violation(
                        code: 'response.header.missing',
                        operation: $matched->operation->key,
                        location: 'header',
                        instancePath: $name,
                        specPointer: $pointer,
                        expected: 'required response header',
                        actual: null,
                        message: sprintf('Required response header "%s" is missing', $name),
                    );
                }

                continue;
            }
            $violations = [...$violations, ...$this->validateHeader($matched, $name, $header, $response->getHeaderLine($name), $dialect, $pointer)];
        }

        $content = $definition['content'] ?? null;
        if (!is_array($content)) {
            return new ValidationResult($violations);
        }

        try {
            $body = $this->bodyContents($response);
        } catch (MessageBodyUnreadable) {
            $violations[] = new Violation(
                code: 'response.body.unreadable',
                operation: $matched->operation->key,
                location: 'body',
                instancePath: '$',
                specPointer: $basePointer . '/content',
                expected: 'readable body stream',
                actual: 'stream that made no progress',
                message: 'Response body stream could not be read',
            );

            return new ValidationResult($violations);
        } catch (MessageBodyTooLarge) {
            $violations[] = new Violation(
                code: 'response.body.too_large',
                operation: $matched->operation->key,
                location: 'body',
                instancePath: '$',
                specPointer: $basePointer . '/content',
                expected: sprintf('body up to %d bytes', Contract::MAX_MESSAGE_BODY_BYTES),
                actual: 'body exceeds validation byte budget',
                message: sprintf('Response body exceeds %d bytes', Contract::MAX_MESSAGE_BODY_BYTES),
            );

            return new ValidationResult($violations);
        }
        if ($body === null) {
            $violations[] = new Violation(
                code: 'response.body.non_seekable',
                operation: $matched->operation->key,
                location: 'body',
                instancePath: '$',
                specPointer: $basePointer . '/content',
                expected: 'seekable body stream',
                actual: 'non-seekable body stream',
                message: 'Response body stream must be seekable for validation',
            );

            return new ValidationResult($violations);
        }
        if ($body === '') {
            return new ValidationResult([...$violations, ...$this->missingBody($matched, $content, $response->getStatusCode(), $basePointer)]);
        }
        $mediaType = $this->mediaTypeOf($response);
        $mediaDefinition = $this->mediaDefinition($content, $mediaType);
        if ($mediaDefinition === null) {
            $violations[] = new Violation(
                code: 'response.body.media_type',
                operation: $matched->operation->key,
                location: 'body',
                instancePath: '$',
                specPointer: $basePointer . '/content',
                expected: array_keys($content),
                actual: $mediaType,
                message: sprintf('Response media type "%s" is not declared', $mediaType),
            );

            return new ValidationResult($violations);
        }
        if (!MediaType::isJson($mediaType)) {
            return new ValidationResult([...$violations, ...$this->validateOpaqueBody($matched, $mediaType, $body, $mediaDefinition, $dialect, $basePointer)]);
        }

        try {
            /** @var mixed $value */
            $value = json_decode($body, depth: 64, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $violations[] = new Violation(
                code: 'response.body.json',
                operation: $matched->operation->key,
                location: 'body',
                instancePath: '$',
                specPointer: $basePointer . '/content/' . $this->escape($mediaType),
                expected: 'valid JSON',
                actual: $body,
                message: 'Response body is not valid JSON',
            );

            return new ValidationResult($violations);
        }
        /** @var mixed $schemaValue */
        $schemaValue = $mediaDefinition['schema'] ?? null;
        // Read exactly as the request side reads it: a declaration neither
        // side can make sense of is a contract error in both, where it used to
        // raise here and pass silently there.
        $schema = $this->values->schema($schemaValue);
        $schemaValid = $schema === null
            ? !$this->declaresNothingValid($mediaDefinition)
            : $this->schemas->isValid($value, $schema, $dialect, direction: 'response');
        if (!$schemaValid) {
            $violations[] = new Violation(
                code: 'response.body.schema',
                operation: $matched->operation->key,
                location: 'body',
                instancePath: '$',
                specPointer: $basePointer . '/content/' . $this->escape($mediaType) . '/schema',
                expected: $schema,
                actual: $value,
                message: 'Response body does not match its schema',
            );
        }

        return new ValidationResult($violations);
    }

    /**
     * A response that declares a schema and answers with nothing has not
     * answered. Checking only the bodies that arrived meant the one failure
     * contract testing exists to catch — the endpoint that returns an empty
     * 200 — was the one it passed, while the request side has always reported
     * `request.body.missing` for the mirror case.
     *
     * The statuses that carry no body by definition are excluded: 204 and 304
     * per RFC 9110 §15, and every response to a HEAD request, which repeats
     * the GET headers without the content they describe.
     *
     * @param array<array-key, mixed> $content
     * @return list<Violation>
     */
    private function missingBody(MatchedOperation $matched, array $content, int $status, string $basePointer): array
    {
        if ($matched->operation->method === 'HEAD' || in_array($status, [204, 304], strict: true)) {
            return [];
        }
        $declaresSchema = false;
        /** @var mixed $definition */
        foreach ($content as $definition) {
            if (is_array($definition) && array_key_exists('schema', $definition) && $definition['schema'] !== true) {
                $declaresSchema = true;
                break;
            }
        }
        if (!$declaresSchema) {
            return [];
        }

        return [new Violation(
            code: 'response.body.missing',
            operation: $matched->operation->key,
            location: 'body',
            instancePath: '$',
            specPointer: $basePointer . '/content',
            expected: array_keys($content),
            actual: null,
            message: 'Declared response body is missing',
        )];
    }

    /**
     * A declared non-JSON media type is validated as far as its schema
     * allows: the body is opaque without a schema, the raw payload is the
     * string value of a string-typed schema, and any other schema cannot be
     * evaluated against an undecoded payload.
     *
     * @param array<array-key, mixed> $mediaDefinition
     * @return list<Violation>
     */
    private function validateOpaqueBody(
        MatchedOperation $matched,
        string $mediaType,
        string $body,
        array $mediaDefinition,
        SchemaDialect $dialect,
        string $basePointer,
    ): array {
        $schema = $this->declaredSchema($mediaDefinition);

        return match (OpaqueBodyVerdict::of($schema, $body, $this->schemas, $dialect, 'response')) {
            OpaqueBodyVerdict::Opaque, OpaqueBodyVerdict::Valid => [],
            OpaqueBodyVerdict::Unsupported => [new Violation(
                code: 'response.body.unsupported',
                operation: $matched->operation->key,
                location: 'body',
                instancePath: '$',
                specPointer: $basePointer . '/content/' . $this->escape($mediaType) . '/schema',
                expected: 'JSON media type or string-typed schema',
                actual: $mediaType,
                message: sprintf('Response media type "%s" cannot be validated against a non-string schema', $mediaType),
            )],
            OpaqueBodyVerdict::Invalid => [new Violation(
                code: 'response.body.schema',
                operation: $matched->operation->key,
                location: 'body',
                instancePath: '$',
                specPointer: $basePointer . '/content/' . $this->escape($mediaType) . '/schema',
                expected: $schema,
                actual: $body,
                message: 'Response body does not match its schema',
            )],
        };
    }

    /**
     * A present Header Object is decoded with the `simple` style and
     * validated against its schema, as request header parameters are; for
     * array and object schemas the optional whitespace HTTP allows around
     * the comma separators of a multi-valued header is dropped first. A
     * schema-less declaration only asserts presence; the `content` form and
     * any style other than `simple` cannot be evaluated and fail closed.
     *
     * @param array<array-key, mixed> $header
     * @return list<Violation>
     */
    private function validateHeader(
        MatchedOperation $matched,
        string $name,
        array $header,
        string $wire,
        SchemaDialect $dialect,
        string $pointer,
    ): array {
        $schema = $this->declaredSchema($header);
        $style = $header['style'] ?? 'simple';
        if ($schema === null || $style !== 'simple') {
            if ($schema === null && !array_key_exists('content', $header)) {
                return [];
            }

            return [new Violation(
                code: 'response.header.unsupported',
                operation: $matched->operation->key,
                location: 'header',
                instancePath: $name,
                specPointer: $pointer,
                expected: 'simple-style Header Object with a schema',
                actual: $schema === null ? 'content' : $style,
                message: sprintf('Response header "%s" cannot be validated against its declaration', $name),
            )];
        }

        $kind = $this->values->kind($schema);
        if ($kind !== ParameterKind::Scalar) {
            $wire = $this->parameters->withoutHeaderWhitespace($wire);
        }

        try {
            $parsed = $this->parameters->parse(
                name: $name,
                wire: $wire,
                style: ParameterStyle::Simple,
                explode: ($header['explode'] ?? false) === true,
                kind: $kind,
            );
            /** @var mixed $value */
            $value = $this->values->coerce($parsed, $schema);
        } catch (InvalidContract $exception) {
            // A declaration this package cannot read is the document's defect,
            // not the response's; `InvalidContract` extends
            // `InvalidArgumentException`, so without this it would be reported
            // as a header the response failed to serialize.
            throw $exception;
        } catch (\InvalidArgumentException) {
            return [new Violation(
                code: 'response.header.serialization',
                operation: $matched->operation->key,
                location: 'header',
                instancePath: $name,
                specPointer: $pointer,
                expected: 'simple',
                actual: $wire,
                message: sprintf('Response header "%s" cannot be deserialized', $name),
            )];
        }
        if ($this->schemas->isValid($value, $schema, $dialect, direction: 'response')) {
            return [];
        }

        return [new Violation(
            code: 'response.header.schema',
            operation: $matched->operation->key,
            location: 'header',
            instancePath: $name,
            specPointer: $pointer . '/schema',
            expected: $schema,
            actual: $value,
            message: sprintf('Response header "%s" does not match its schema', $name),
        )];
    }
}
