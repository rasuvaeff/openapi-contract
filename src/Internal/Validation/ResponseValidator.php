<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Validation;

use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Internal\Response\ResponseSelector;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaDialect;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaValidator;
use Rasuvaeff\OpenApiContract\MatchedOperation;
use Rasuvaeff\OpenApiContract\ValidationResult;
use Rasuvaeff\OpenApiContract\Violation;

/**
 * @internal
 */
final readonly class ResponseValidator
{
    use MessageReading;

    public function __construct(
        private ResponseSelector $selector = new ResponseSelector(),
        private SchemaValidator $schemas = new SchemaValidator(),
    ) {}

    public function validate(
        MatchedOperation $matched,
        ResponseInterface $response,
        SchemaDialect $dialect,
    ): ValidationResult {
        $selected = $this->selector->select($matched->operation->responses, $response->getStatusCode());
        if (!$selected instanceof \Rasuvaeff\OpenApiContract\Internal\Response\SelectedResponse) {
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
            if (!is_string($name) || !is_array($header) || ($header['required'] ?? false) !== true) {
                continue;
            }
            if (!$response->hasHeader($name)) {
                $violations[] = new Violation(
                    code: 'response.header.missing',
                    operation: $matched->operation->key,
                    location: 'header',
                    instancePath: $name,
                    specPointer: $basePointer . '/headers/' . $this->escape($name),
                    expected: 'required response header',
                    actual: null,
                    message: sprintf('Required response header "%s" is missing', $name),
                );
            }
        }

        $content = $definition['content'] ?? null;
        if (!is_array($content) || $content === []) {
            return new ValidationResult($violations);
        }

        try {
            $body = $this->bodyContents($response);
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
            return new ValidationResult($violations);
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
        if (!$this->isJsonMediaType($mediaType)) {
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
        $schema = is_array($schemaValue) && !array_is_list($schemaValue) ? $schemaValue : null;
        if (is_array($schema) && !array_is_list($schema)) {
            /** @var array<string, mixed> $schema */
            $schemaValid = $this->schemas->isValid($value, $schema, $dialect, direction: 'response');
        } else {
            $schemaValid = true;
        }
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
        if ($schema === null || $schema === []) {
            return [];
        }
        if (!$this->isStringSchema($schema)) {
            return [new Violation(
                code: 'response.body.unsupported',
                operation: $matched->operation->key,
                location: 'body',
                instancePath: '$',
                specPointer: $basePointer . '/content/' . $this->escape($mediaType) . '/schema',
                expected: 'JSON media type or string-typed schema',
                actual: $mediaType,
                message: sprintf('Response media type "%s" cannot be validated against a non-string schema', $mediaType),
            )];
        }
        if ($this->schemas->isValid($body, $schema, $dialect, direction: 'response')) {
            return [];
        }

        return [new Violation(
            code: 'response.body.schema',
            operation: $matched->operation->key,
            location: 'body',
            instancePath: '$',
            specPointer: $basePointer . '/content/' . $this->escape($mediaType) . '/schema',
            expected: $schema,
            actual: $body,
            message: 'Response body does not match its schema',
        )];
    }
}
