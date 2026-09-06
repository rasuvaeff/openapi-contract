<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Compilation;

use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedDialect;
use Rasuvaeff\OpenApiContract\Internal\Reference\DocumentGraph;
use Rasuvaeff\OpenApiContract\Internal\Reference\JsonPointerResolver;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaDialect;
use Rasuvaeff\OpenApiContract\InvalidContract;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\OpenApiContract\UnsupportedSerialization;
use Rasuvaeff\OpenApiContract\UnsupportedVersion;

/**
 * Compiles a raw OpenAPI document array into the operation list a Contract runs on.
 *
 * @psalm-import-type CompiledParameter from Operation
 *
 * @internal
 */
final readonly class DocumentCompiler
{
    /** @param array<string, mixed> $document */
    public function compile(array $document, ?DocumentGraph $graph = null): CompiledDocument
    {
        $version = $document['openapi'] ?? null;
        if (!is_string($version) || !preg_match('/^3\.(0|1)\.[0-9]+$/', $version)) {
            throw UnsupportedVersion::forVersion(is_string($version) ? $version : 'missing');
        }
        $dialect = str_starts_with($version, '3.0.') ? SchemaDialect::OpenApi30 : SchemaDialect::OpenApi31;
        $this->assertDocumentDialect($document, $dialect);
        if (!isset($document['paths']) || !is_array($document['paths']) || $document['paths'] === []) {
            throw new InvalidContract('OpenAPI document must contain a non-empty paths object');
        }

        $resolver = new JsonPointerResolver($document, graph: $graph);
        $rootServers = $this->servers($document['servers'] ?? null);
        $securitySchemes = (new SecuritySchemeCompiler())->compile($document['components'] ?? null, $dialect, $resolver);
        $schemeNames = array_keys($securitySchemes);
        $rootSecurity = array_key_exists('security', $document)
            ? $this->securityRequirements($document['security'], $schemeNames)
            : [];
        $operations = [];
        $templates = [];
        $paths = $document['paths'];
        /** @var mixed $pathItem */
        foreach ($paths as $path => $pathItem) {
            if (!is_string($path)) {
                throw new InvalidContract('OpenAPI paths keys must be strings');
            }
            $pathString = $path;
            if (str_starts_with($pathString, 'x-')) {
                continue;
            }
            if (!str_starts_with($pathString, '/')) {
                throw new InvalidContract(sprintf('OpenAPI path "%s" must start with /', $pathString));
            }
            if (!is_array($pathItem)) {
                throw new InvalidContract(sprintf('OpenAPI path item "%s" must be an object', $pathString));
            }
            /** @var array<array-key, mixed> $pathItem */
            $pathItem = $resolver->resolve($pathItem);
            $pathServers = $this->servers($pathItem['servers'] ?? null, $rootServers);
            $pathParameters = $this->parameterList($pathItem['parameters'] ?? null, sprintf('path item "%s"', $pathString));
            foreach (['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'] as $method) {
                if (!array_key_exists($method, $pathItem)) {
                    continue;
                }
                $raw = $pathItem[$method];
                if (!is_array($raw)) {
                    throw new InvalidContract(sprintf('Operation at %s %s must be an object', strtoupper($method), $pathString));
                }
                /** @var array<array-key, mixed> $raw */
                /** @var mixed $operationIdValue */
                $operationIdValue = $raw['operationId'] ?? null;
                if ($operationIdValue === null) {
                    $operationId = null;
                } elseif (is_string($operationIdValue) && $operationIdValue !== '') {
                    $operationId = $operationIdValue;
                } else {
                    throw new InvalidContract(sprintf('Operation at %s %s has an invalid operationId', strtoupper($method), $pathString));
                }
                $key = $operationId ?? strtoupper($method) . ' ' . $pathString;
                if (isset($operations[$key])) {
                    throw new InvalidContract(sprintf('Duplicate operation identity "%s"', $key));
                }
                $where = sprintf('operation %s %s', strtoupper($method), $pathString);
                $rawParameters = $this->parameterList($raw['parameters'] ?? null, $where);
                $normalizedTemplate = preg_replace('/\{[^{}]+\}/', '{}', $pathString);
                if (!is_string($normalizedTemplate)) {
                    throw new \LogicException('Path template normalization failed');
                }
                $templateKey = strtoupper($method) . ' ' . $normalizedTemplate;
                if (isset($templates[$templateKey]) && $templates[$templateKey] !== $pathString) {
                    throw new InvalidContract(sprintf(
                        'Ambiguous OpenAPI paths "%s" and "%s" for method %s',
                        $templates[$templateKey],
                        $pathString,
                        strtoupper($method),
                    ));
                }
                $templates[$templateKey] = $pathString;
                $parameters = $this->parameters($pathParameters, $rawParameters, $resolver, $pathString, $method);
                $this->assertPathParameters($pathString, $parameters);
                $servers = $this->servers($raw['servers'] ?? null, $pathServers);
                $operations[$key] = new Operation(
                    key: $key,
                    operationId: $operationId,
                    method: strtoupper($method),
                    path: $pathString,
                    parameters: $parameters,
                    requestBody: $this->requestBody($raw['requestBody'] ?? null, $resolver, $where),
                    responses: $this->resolvedResponses($raw['responses'] ?? null, $resolver, $where),
                    serverBases: array_map(static fn(array $server): string => $server['base'], $servers),
                    security: array_key_exists('security', $raw)
                        ? $this->securityRequirements($raw['security'], $schemeNames)
                        : $rootSecurity,
                    servers: $servers,
                );
            }
        }

        if ($operations === []) {
            // `paths` was non-empty, so the document meant to declare
            // something. A contract with no operation answers `UnknownOperation`
            // to every request, which reads as "this request is wrong" when what
            // is wrong is the document.
            throw new InvalidContract('OpenAPI document declares no operations');
        }

        return new CompiledDocument(dialect: $dialect, operations: array_values($operations), securitySchemes: $securitySchemes);
    }

    /** @param array<string, mixed> $document */
    private function assertDocumentDialect(array $document, SchemaDialect $dialect): void
    {
        $value = $document['jsonSchemaDialect'] ?? null;
        if ($value === null) {
            return;
        }
        if (!is_string($value)) {
            throw UnsupportedDialect::forUri('non-string');
        }
        if ($dialect === SchemaDialect::OpenApi30 || !in_array($value, [
            'https://json-schema.org/draft/2020-12/schema',
            'https://spec.openapis.org/oas/3.1/dialect/base',
        ], strict: true)) {
            throw UnsupportedDialect::forUri($value);
        }
    }

    /**
     * @param list<array{scheme: null|non-empty-string, host: null|non-empty-string, port: null|int, base: non-empty-string}> $fallback
     * @return list<array{scheme: null|non-empty-string, host: null|non-empty-string, port: null|int, base: non-empty-string}>
     */
    private function servers(mixed $servers, array $fallback = [['scheme' => null, 'host' => null, 'port' => null, 'base' => '/']]): array
    {
        if (!is_array($servers) || $servers === []) {
            return $fallback;
        }
        $compiled = [];
        /** @var mixed $server */
        foreach ($servers as $server) {
            if (!is_array($server)) {
                throw new InvalidContract('OpenAPI server must contain a URL');
            }
            $url = $server['url'] ?? null;
            if (!is_string($url) || $url === '') {
                throw new InvalidContract('OpenAPI server must contain a URL');
            }
            $compiled[] = $this->compileServerUrl($this->substituteServerVariables($url, $server['variables'] ?? null), $url);
        }

        return $compiled;
    }

    private function substituteServerVariables(string $url, mixed $variables): string
    {
        if (preg_match_all('/\{([^{}]+)\}/', $url, $matches) === false) {
            throw new \LogicException('Server URL template parsing failed');
        }
        /** @var list<string> $placeholders */
        $placeholders = $matches[1];
        if ($placeholders === []) {
            return $url;
        }
        if (!is_array($variables) || array_is_list($variables)) {
            throw new InvalidContract(sprintf('OpenAPI server URL "%s" uses variables but declares no variables object', $url));
        }
        foreach ($placeholders as $name) {
            $variable = $variables[$name] ?? null;
            if (!is_array($variable)) {
                throw new InvalidContract(sprintf('OpenAPI server URL "%s" uses undeclared variable "%s"', $url, $name));
            }
            $default = $variable['default'] ?? null;
            if (!is_string($default)) {
                throw new InvalidContract(sprintf('OpenAPI server variable "%s" must declare a string default', $name));
            }
            if (array_key_exists('enum', $variable)) {
                $enum = $variable['enum'];
                if (!is_array($enum) || $enum === [] || !array_is_list($enum)) {
                    throw new InvalidContract(sprintf('OpenAPI server variable "%s" enum must be a non-empty list', $name));
                }
                if (!in_array($default, $enum, strict: true)) {
                    throw new InvalidContract(sprintf('OpenAPI server variable "%s" default must be one of its enum values', $name));
                }
            }
            $url = str_replace('{' . $name . '}', $default, $url);
        }

        return $url;
    }

    /**
     * @return array{scheme: null|non-empty-string, host: null|non-empty-string, port: null|int, base: non-empty-string}
     */
    private function compileServerUrl(string $url, string $original): array
    {
        if (preg_match('~^[A-Za-z][A-Za-z0-9+.\-]*+://~', $url) === 1) {
            $parts = parse_url($url);
            if ($parts === false || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
                throw new InvalidContract(sprintf('OpenAPI server URL "%s" has an unsupported form', $original));
            }
            $scheme = strtolower($parts['scheme'] ?? '');
            if (!in_array($scheme, ['http', 'https'], strict: true)) {
                throw new InvalidContract(sprintf('OpenAPI server URL "%s" uses unsupported scheme "%s"', $original, $scheme));
            }
            $host = strtolower($parts['host'] ?? '');
            if ($host === '') {
                throw new InvalidContract(sprintf('OpenAPI server URL "%s" must contain a host', $original));
            }

            return [
                'scheme' => $scheme,
                'host' => $host,
                'port' => $parts['port'] ?? null,
                'base' => $this->serverBase($parts['path'] ?? ''),
            ];
        }
        if (!str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '?') || str_contains($url, '#')) {
            throw new InvalidContract(sprintf('OpenAPI server URL "%s" has an unsupported form', $original));
        }

        return ['scheme' => null, 'host' => null, 'port' => null, 'base' => $this->serverBase($url)];
    }

    /**
     * @return non-empty-string
     */
    private function serverBase(string $path): string
    {
        $base = rtrim($path, '/');

        return $base === '' ? '/' : $base;
    }

    /** RFC 6901: `~` and `/` are the only characters a pointer token escapes. */
    private function escapePointer(string $value): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $value);
    }

    /**
     * @param array<array-key, mixed> $path
     * @param array<array-key, mixed> $operation
     * @return list<CompiledParameter>
     */
    private function parameters(array $path, array $operation, JsonPointerResolver $resolver, string $pathString, string $method): array
    {
        // A Path Item's parameters and an Operation's are merged for lookup,
        // but they live at different pointers; the merged position is not one
        // a reader can find in their document.
        $sources = [
            sprintf('/paths/%s/parameters', $this->escapePointer($pathString)) => array_values($path),
            sprintf('/paths/%s/%s/parameters', $this->escapePointer($pathString), $method) => array_values($operation),
        ];
        $result = [];
        foreach ($sources as $pointer => $declarations) {
            foreach (array_keys($declarations) as $index) {
                $result = [...$result, ...$this->parameter($declarations[$index], $resolver, sprintf('%s/%d', $pointer, $index))];
            }
        }

        return array_values($result);
    }

    /**
     * @param non-empty-string $specPointer
     * @return array<string, CompiledParameter>
     */
    private function parameter(mixed $raw, JsonPointerResolver $resolver, string $specPointer): array
    {
        if (!is_array($raw)) {
            throw new InvalidContract('OpenAPI parameter must be an object');
        }
        $raw = $resolver->resolve($raw);
        $name = $raw['name'] ?? null;
        $in = $raw['in'] ?? null;
        if (!is_string($name) || $name === '' || !is_string($in) || !in_array($in, ['path', 'query', 'header', 'cookie'], strict: true)) {
            throw new InvalidContract('OpenAPI parameter must have a valid name and location');
        }
        /** @var mixed $schemaValue */
        $schemaValue = $raw['schema'] ?? null;
        $schema = $this->resolvedSchema($schemaValue, $resolver);
        $key = $in . ':' . ($in === 'header' ? strtolower($name) : $name);
        /** @var mixed $styleValue */
        $styleValue = $raw['style'] ?? null;
        /** @var mixed $explodeValue */
        $explodeValue = $raw['explode'] ?? null;
        $style = is_string($styleValue) ? $styleValue : ($in === 'query' || $in === 'cookie' ? 'form' : 'simple');
        $this->assertSupportedStyle($name, $in, $style, $raw);
        // A flag written as the string "true" used to read as its default,
        // which made a required parameter optional — the one-bit form of a
        // declaration that says something wrong ending up weaker than one that
        // says nothing.
        $position = sprintf('parameter "%s"', $name);
        $this->assertBoolean($raw['required'] ?? null, $position, 'required');
        $this->assertBoolean($explodeValue, $position, 'explode');
        $this->assertBoolean($raw['allowReserved'] ?? null, $position, 'allowReserved');
        /** @var CompiledParameter $parameter */
        $parameter = [
            'name' => $name,
            'in' => $in,
            'required' => ($raw['required'] ?? false) === true,
            'style' => $style,
            'explode' => is_bool($explodeValue) ? $explodeValue : $style === 'form',
            'allowReserved' => ($raw['allowReserved'] ?? false) === true,
            'schema' => $schema,
            ...array_key_exists('example', $raw) ? ['example' => $raw['example']] : [],
            ...array_key_exists('examples', $raw) ? ['examples' => $this->namedExamples($name, $raw['examples'])] : [],
            'specPointer' => $specPointer,
        ];

        return [$key => $parameter];
    }

    /**
     * @return array<string, mixed>
     */
    private function namedExamples(string $name, mixed $examples): array
    {
        if (!is_array($examples)) {
            throw new InvalidContract(sprintf('OpenAPI parameter "%s" examples must be a map of named examples', $name));
        }
        foreach (array_keys($examples) as $exampleName) {
            if (!is_string($exampleName)) {
                throw new InvalidContract(sprintf('OpenAPI parameter "%s" examples must be a map of named examples', $name));
            }
        }

        /** @var array<string, mixed> $examples */
        return $examples;
    }

    /**
     * @param list<CompiledParameter> $parameters
     */
    private function assertPathParameters(string $template, array $parameters): void
    {
        $matched = preg_match_all('/\{([^{}]+)\}/', $template, $matches);
        if ($matched === false) {
            throw new \LogicException('Path template parsing failed');
        }
        /** @var list<string> $placeholders */
        $placeholders = $matches[1] ?? [];
        $declared = [];
        foreach ($parameters as $parameter) {
            if ($parameter['in'] !== 'path') {
                continue;
            }
            if (!in_array($parameter['name'], $placeholders, strict: true)) {
                throw new InvalidContract(sprintf(
                    'Path parameter "%s" is not present in template "%s"',
                    $parameter['name'],
                    $template,
                ));
            }
            if (!$parameter['required']) {
                throw new InvalidContract(sprintf(
                    'Path parameter "%s" in template "%s" must declare required: true',
                    $parameter['name'],
                    $template,
                ));
            }
            $declared[] = $parameter['name'];
        }
        foreach ($placeholders as $placeholder) {
            if (!in_array($placeholder, $declared, strict: true)) {
                throw new InvalidContract(sprintf(
                    'Path template "%s" has no path parameter named "%s"',
                    $template,
                    $placeholder,
                ));
            }
        }
    }

    /** @param array<array-key, mixed> $parameter */
    private function assertSupportedStyle(string $name, string $in, string $style, array $parameter): void
    {
        if (array_key_exists('content', $parameter)) {
            throw new UnsupportedSerialization(sprintf('Parameter "%s" uses unsupported content serialization', $name));
        }
        $supported = match ($in) {
            'path' => ['simple', 'label', 'matrix'],
            'query' => ['form', 'spaceDelimited', 'pipeDelimited', 'deepObject'],
            'header' => ['simple'],
            'cookie' => ['form'],
            default => [],
        };
        if (!in_array($style, $supported, strict: true)) {
            throw new UnsupportedSerialization(sprintf('Parameter "%s" uses unsupported %s style "%s"', $name, $in, $style));
        }
    }

    /**
     * The Request Body Object an operation declares, resolved and checked.
     *
     * A declaration this compiler cannot read is rejected here rather than
     * folded into "no body declared": the response side has always been
     * fail-closed about the same shapes, and a document cannot mean one thing
     * in one direction and nothing in the other.
     *
     * @return array<array-key, mixed>
     */
    private function requestBody(mixed $value, JsonPointerResolver $resolver, string $where): array
    {
        if ($value === null) {
            return [];
        }
        $body = $resolver->resolve($this->object($value, sprintf('requestBody of %s', $where)));
        $this->assertBoolean($body['required'] ?? null, sprintf('requestBody of %s', $where), 'required');
        $this->assertContent($body['content'] ?? null, sprintf('requestBody of %s', $where));

        return $body;
    }

    /** @return array<string, mixed> */
    private function resolvedSchema(mixed $value, JsonPointerResolver $resolver): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidContract('OpenAPI parameter schema must be an object');
        }
        $schema = $resolver->resolve($value);
        foreach (array_keys($schema) as $key) {
            if (!is_string($key)) {
                throw new InvalidContract('OpenAPI schema keys must be strings');
            }
        }
        $this->assertEncodable($schema, 'OpenAPI parameter schema');

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    /** @return array<array-key, mixed> */
    private function resolvedResponses(mixed $value, JsonPointerResolver $resolver, string $where): array
    {
        if (!is_array($value) || $value === []) {
            throw new InvalidContract('Operation responses must be an object');
        }
        $result = [];
        /** @var mixed $response */
        foreach ($value as $key => $response) {
            if (!is_array($response)) {
                throw new InvalidContract(sprintf('Response "%s" must be an object', (string) $key));
            }
            $resolved = $resolver->resolve($response);
            $position = sprintf('response "%s" of %s', (string) $key, $where);
            $this->assertContent($resolved['content'] ?? null, $position);
            $this->assertHeaders($resolved['headers'] ?? null, $position);
            $result[$key] = $resolved;
        }

        return $result;
    }

    /**
     * The `parameters` a Path Item or an Operation declares. A value that is
     * not a list of declarations used to be read as an empty one, which turned
     * a malformed document into a weaker contract than a silent one.
     *
     * @return list<mixed>
     */
    private function parameterList(mixed $value, string $where): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidContract(sprintf('OpenAPI parameters of %s must be a list', $where));
        }

        return $value;
    }

    /**
     * Every Media Type Object a `content` map declares, down to the schemas
     * the validators read out of it: a shape checked here cannot surprise a
     * validator halfway through a request.
     */
    private function assertContent(mixed $content, string $where): void
    {
        if ($content === null) {
            return;
        }
        /** @var mixed $definition */
        foreach ($this->object($content, sprintf('content of %s', $where)) as $mediaType => $definition) {
            if (!is_string($mediaType)) {
                throw new InvalidContract(sprintf('OpenAPI content keys of %s must be media type strings', $where));
            }
            $position = sprintf('media type "%s" of %s', $mediaType, $where);
            $media = $this->object($definition, $position);
            $this->assertSchema($media['schema'] ?? null, $position);
            $this->assertEncoding($media['encoding'] ?? null, $position);
        }
    }

    /** Encoding Objects, and the Header Objects they declare for a multipart part. */
    private function assertEncoding(mixed $encoding, string $where): void
    {
        if ($encoding === null) {
            return;
        }
        /** @var mixed $declaration */
        foreach ($this->object($encoding, sprintf('encoding of %s', $where)) as $property => $declaration) {
            if (!is_string($property)) {
                throw new InvalidContract(sprintf('OpenAPI encoding keys of %s must be property name strings', $where));
            }
            $position = sprintf('encoding "%s" of %s', $property, $where);
            $this->assertHeaders($this->object($declaration, $position)['headers'] ?? null, $position);
        }
    }

    private function assertHeaders(mixed $headers, string $where): void
    {
        if ($headers === null) {
            return;
        }
        /** @var mixed $header */
        foreach ($this->object($headers, sprintf('headers of %s', $where)) as $name => $header) {
            if (!is_string($name)) {
                throw new InvalidContract(sprintf('OpenAPI header names of %s must be strings', $where));
            }
            $position = sprintf('header "%s" of %s', $name, $where);
            $declaration = $this->object($header, $position);
            $this->assertBoolean($declaration['required'] ?? null, $position, 'required');
            $this->assertSchema($declaration['schema'] ?? null, $position);
        }
    }

    /**
     * A Schema Object as the validators may read it: absent, one of the two
     * boolean schemas, or a keyword map. A list — the shape `schema: []`
     * decodes to — is none of those, and used to reach the value decoder at
     * request time, where it left as a bare `InvalidArgumentException` in one
     * direction and as "nothing declared" in the other.
     */
    private function assertSchema(mixed $schema, string $where): void
    {
        if ($schema === null || is_bool($schema)) {
            return;
        }
        $object = $this->object($schema, sprintf('schema of %s', $where));
        foreach (array_keys($object) as $key) {
            if (!is_string($key)) {
                throw new InvalidContract(sprintf('OpenAPI schema keys of %s must be strings', $where));
            }
        }
        $this->assertEncodable($object, sprintf('OpenAPI schema of %s', $where));
    }

    /**
     * A schema the validation backend can be handed at all. Everything on the
     * way to it — the compilation cache key, the directional rewrite, the
     * backend's own parse — goes through `json_encode`, which cannot express
     * `NAN`/`INF` (YAML spells both) or a malformed UTF-8 string. Discovering
     * that on the first request that happens to use the schema turned a
     * document defect into a raw `JsonException` out of a validate call.
     *
     * @param array<array-key, mixed> $schema
     */
    private function assertEncodable(array $schema, string $where): void
    {
        try {
            json_encode($schema, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidContract(sprintf('%s cannot be encoded for validation: %s', $where, $exception->getMessage()), $exception->getCode(), previous: $exception);
        }
    }

    private function assertBoolean(mixed $value, string $where, string $field): void
    {
        if ($value !== null && !is_bool($value)) {
            throw new InvalidContract(sprintf('OpenAPI %s of %s must be a boolean', $field, $where));
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function object(mixed $value, string $where): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidContract(sprintf('OpenAPI %s must be an object', $where));
        }

        /** @var array<array-key, mixed> $value */
        return $value;
    }

    /**
     * @param list<string> $securitySchemes
     * @return list<array<string, list<string>>>
     */
    private function securityRequirements(mixed $value, array $securitySchemes): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidContract('OpenAPI security must be a list of requirement objects');
        }
        /** @var list<mixed> $value */
        $result = [];
        foreach (array_keys($value) as $index) {
            /** @var mixed $requirementValue */
            $requirementValue = $value[$index];
            if (!is_array($requirementValue)) {
                throw new InvalidContract('OpenAPI security requirement must be an object');
            }
            if ($requirementValue !== [] && array_is_list($requirementValue)) {
                throw new InvalidContract('OpenAPI security requirement must be an object');
            }
            $requirement = $requirementValue;
            $normalized = [];
            /** @var mixed $scopes */
            foreach ($requirement as $name => $scopes) {
                if (!is_string($name) || $name === '' || !in_array($name, $securitySchemes, strict: true)) {
                    throw new InvalidContract(sprintf('OpenAPI security requirement references unknown scheme "%s"', (string) $name));
                }
                if (!is_array($scopes) || !array_is_list($scopes)) {
                    throw new InvalidContract(sprintf('OpenAPI security scopes for "%s" must be a list', $name));
                }
                /** @var mixed $scope */
                foreach ($scopes as $scope) {
                    if (!is_string($scope)) {
                        throw new InvalidContract(sprintf('OpenAPI security scopes for "%s" must contain strings', $name));
                    }
                }
                /** @var list<string> $scopes */
                $normalized[$name] = $scopes;
            }
            $result[] = $normalized;
        }

        return $result;
    }
}
