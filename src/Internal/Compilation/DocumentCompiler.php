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
        $rootServers = $this->serverBases($document['servers'] ?? null);
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
            $pathServers = $this->serverBases($pathItem['servers'] ?? null, $rootServers);
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
                $operations[$key] = new Operation(
                    key: $key,
                    operationId: $operationId,
                    method: strtoupper($method),
                    path: $pathString,
                    parameters: $parameters,
                    requestBody: $this->resolvedObject($raw['requestBody'] ?? null, $resolver),
                    responses: $this->resolvedResponses($raw['responses'] ?? null, $resolver),
                    serverBases: $this->serverBases($raw['servers'] ?? null, $pathServers),
                    security: array_key_exists('security', $raw)
                        ? $this->securityRequirements($raw['security'], $securitySchemes)
                        : $rootSecurity,
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
     * @param list<string> $fallback
     * @return list<string>
     */
    private function serverBases(mixed $servers, array $fallback = ['/']): array
    {
        if (!is_array($servers) || $servers === []) {
            return $fallback;
        }
        $bases = [];
        /** @var mixed $server */
        foreach ($servers as $server) {
            if (!is_array($server)) {
                throw new InvalidContract('OpenAPI server must contain a URL');
            }
            $url = $server['url'] ?? null;
            if (!is_string($url)) {
                throw new InvalidContract('OpenAPI server must contain a URL');
            }
            $parsed = parse_url($url, PHP_URL_PATH);
            $bases[] = is_string($parsed) && $parsed !== '' ? rtrim($parsed, '/') : '/';
        }

        return $bases;
    }

    /**
     * @param array<array-key, mixed> $path
     * @param array<array-key, mixed> $operation
     * @return list<array{name: non-empty-string, in: 'path'|'query'|'header'|'cookie', required: bool, style: string, explode: bool, allowReserved: bool, schema: array<string, mixed>}>
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
            $result[$key] = [
                'name' => $name,
                'in' => $in,
                'required' => ($raw['required'] ?? false) === true,
                'style' => $style,
                'explode' => is_bool($explodeValue) ? $explodeValue : $style === 'form',
                'allowReserved' => ($raw['allowReserved'] ?? false) === true,
                'schema' => $schema,
            ];
        }

        return array_values($result);
    }

    /**
     * @param list<array{name: non-empty-string, in: 'path'|'query'|'header'|'cookie', required: bool, style: string, explode: bool, allowReserved: bool, schema: array<string, mixed>}> $parameters
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
