<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Compilation;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\OpenApiContract\SchemaDirection;

/**
 * Every Schema Object the validators read out of an operation, with the
 * direction each is read in, so a contract can compile all of them while it
 * is built. The positions are the ones the validators visit and no others:
 * parameter schemas, request media types and their encoding headers on the
 * request side; response media types and response headers on the response
 * side. A boolean schema has nothing to compile, and the empty schema `{}`
 * compiles like any other.
 *
 * @internal
 */
final readonly class OperationSchemas
{
    /**
     * @return list<array{0: array<string, mixed>, 1: SchemaDirection}>
     */
    public function of(Operation $operation): array
    {
        $schemas = [];
        foreach ($operation->parameters as $parameter) {
            $schemas[] = [$parameter['schema'], SchemaDirection::Request];
        }
        /** @var mixed $content */
        $content = $operation->requestBody['content'] ?? null;
        foreach ($this->mediaSchemas($content) as $schema) {
            $schemas[] = [$schema, SchemaDirection::Request];
        }
        /** @var mixed $response */
        foreach ($operation->responses as $response) {
            if (!is_array($response)) {
                continue;
            }
            foreach ($this->mediaSchemas($response['content'] ?? null) as $schema) {
                $schemas[] = [$schema, SchemaDirection::Response];
            }
            foreach ($this->headerSchemas($response['headers'] ?? null) as $schema) {
                $schemas[] = [$schema, SchemaDirection::Response];
            }
        }

        return $schemas;
    }

    /**
     * The schemas of a `content` map: each Media Type Object's own, and the
     * ones its `encoding` declares for multipart part headers — read on the
     * side the body travels on, which for a request body is the request.
     *
     * @return list<array<string, mixed>>
     */
    private function mediaSchemas(mixed $content): array
    {
        if (!is_array($content)) {
            return [];
        }
        $schemas = [];
        /** @var mixed $definition */
        foreach ($content as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $schema = $this->schema($definition['schema'] ?? null);
            if ($schema !== null) {
                $schemas[] = $schema;
            }
            /** @var mixed $encoding */
            $encoding = $definition['encoding'] ?? null;
            if (!is_array($encoding)) {
                continue;
            }
            /** @var mixed $property */
            foreach ($encoding as $property) {
                if (is_array($property)) {
                    $schemas = [...$schemas, ...$this->headerSchemas($property['headers'] ?? null)];
                }
            }
        }

        return $schemas;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function headerSchemas(mixed $headers): array
    {
        if (!is_array($headers)) {
            return [];
        }
        $schemas = [];
        /** @var mixed $header */
        foreach ($headers as $header) {
            if (!is_array($header)) {
                continue;
            }
            $schema = $this->schema($header['schema'] ?? null);
            if ($schema !== null) {
                $schemas[] = $schema;
            }
        }

        return $schemas;
    }

    /**
     * A Schema Object as the validators read one: a keyword map, or the
     * empty schema. A boolean has nothing to compile, and any other shape
     * has already been refused by the compiler.
     *
     * @return array<string, mixed>|null
     */
    private function schema(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        if ($value !== [] && array_is_list($value)) {
            return null;
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                return null;
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
