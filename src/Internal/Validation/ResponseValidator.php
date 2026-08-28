<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Validation;

use Psr\Http\Message\ResponseInterface;
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
        $body = $this->bodyContents($response);
        if (!is_array($content) || $content === [] || $body === '') {
            return new ValidationResult($violations);
        }
        $mediaType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0]));
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
            $violations[] = new Violation(
                code: 'response.body.media_type',
                operation: $matched->operation->key,
                location: 'body',
                instancePath: '$',
                specPointer: $basePointer . '/content',
                expected: 'JSON media type',
                actual: $mediaType,
                message: sprintf('Response media type "%s" is not supported', $mediaType),
            );

            return new ValidationResult($violations);
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

    private function bodyContents(ResponseInterface $response): string
    {
        $stream = $response->getBody();
        if (!$stream->isSeekable()) {
            return $stream->getContents();
        }
        $position = $stream->tell();
        $stream->rewind();
        $contents = $stream->getContents();
        $stream->seek($position);

        return $contents;
    }

    /** @param array<array-key, mixed> $content */
    private function mediaDefinition(array $content, string $mediaType): ?array
    {
        foreach ($content as $declared => $definition) {
            if (!is_string($declared) || !is_array($definition)) {
                continue;
            }
            if ($this->mediaMatches($declared, $mediaType)) {
                return $definition;
            }
        }

        return null;
    }

    private function isJsonMediaType(string $mediaType): bool
    {
        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }

    private function mediaMatches(string $declared, string $actual): bool
    {
        $declared = strtolower(trim(explode(';', $declared, 2)[0]));
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

    private function escape(string $value): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $value);
    }
}
