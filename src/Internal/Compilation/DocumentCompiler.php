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
        $securitySchemes = $this->securitySchemes($document['components'] ?? null);
        $rootSecurity = array_key_exists('security', $document)
            ? $this->securityRequirements($document['security'], $securitySchemes)
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
            /** @var mixed $pathParametersValue */
            $pathParametersValue = $pathItem['parameters'] ?? null;
            $pathParameters = is_array($pathParametersValue) ? $pathParametersValue : [];
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
                /** @var mixed $rawParameters */
                $rawParameters = $raw['parameters'] ?? null;
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
                $parameters = $this->parameters($pathParameters, is_array($rawParameters) ? $rawParameters : [], $resolver);
                $this->assertPathParameters($pathString, $parameters);
                $servers = $this->servers($raw['servers'] ?? null, $pathServers);
                $operations[$key] = new Operation(
                    key: $key,
                    operationId: $operationId,
                    method: strtoupper($method),
                    path: $pathString,
                    parameters: $parameters,
                    requestBody: $this->resolvedObject($raw['requestBody'] ?? null, $resolver),
                    responses: $this->resolvedResponses($raw['responses'] ?? null, $resolver),
                    serverBases: array_map(static fn(array $server): string => $server['base'], $servers),
                    security: array_key_exists('security', $raw)
                        ? $this->securityRequirements($raw['security'], $securitySchemes)
                        : $rootSecurity,
                    servers: $servers,
                );
            }
        }

        return new CompiledDocument(dialect: $dialect, operations: array_values($operations));
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

    /**
     * @param array<array-key, mixed> $path
     * @param array<array-key, mixed> $operation
     * @return list<CompiledParameter>
     */
    private function parameters(array $path, array $operation, JsonPointerResolver $resolver): array
    {
        $result = [];
        foreach ([...$path, ...$operation] as $raw) {
            if (!is_array($raw)) {
                continue;
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
            ];
            $result[$key] = $parameter;
        }

        return array_values($result);
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

    /** @return array<array-key, mixed> */
    private function resolvedObject(mixed $value, JsonPointerResolver $resolver): array
    {
        return is_array($value) ? $resolver->resolve($value) : [];
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

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    /** @return array<array-key, mixed> */
    private function resolvedResponses(mixed $value, JsonPointerResolver $resolver): array
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
            $result[$key] = $resolver->resolve($response);
        }

        return $result;
    }

    /** @return list<string> */
    private function securitySchemes(mixed $components): array
    {
        if ($components === null) {
            return [];
        }
        if (!is_array($components)) {
            throw new InvalidContract('OpenAPI components must be an object');
        }
        if ($components !== [] && array_is_list($components)) {
            throw new InvalidContract('OpenAPI components must be an object');
        }
        /** @var mixed $schemesValue */
        $schemesValue = array_key_exists('securitySchemes', $components) ? $components['securitySchemes'] : [];
        if (!is_array($schemesValue) || ($schemesValue !== [] && array_is_list($schemesValue))) {
            throw new InvalidContract('OpenAPI securitySchemes must be an object');
        }
        /** @var array<array-key, mixed> $schemes */
        $schemes = $schemesValue;
        foreach (array_keys($schemes) as $name) {
            if (!is_string($name) || $name === '' || !is_array($schemes[$name]) || ($schemes[$name] !== [] && array_is_list($schemes[$name]))) {
                throw new InvalidContract('OpenAPI security scheme must be a named object');
            }
        }
        $names = [];
        foreach (array_keys($schemes) as $name) {
            if (!is_string($name)) {
                throw new InvalidContract('OpenAPI security scheme names must be strings');
            }
            $names[] = $name;
        }

        return $names;
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
