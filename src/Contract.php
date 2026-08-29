<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedDialect;
use Rasuvaeff\OpenApiContract\Internal\Reference\JsonPointerResolver;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaDialect;
use Rasuvaeff\OpenApiContract\Internal\Validation\RequestValidator;
use Rasuvaeff\OpenApiContract\Internal\Validation\ResponseValidator;

/**
 * Compiled OpenAPI 3.0/3.1 contract.
 *
 * @api
 */
final readonly class Contract
{
    public const int MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;

    /** @var list<Operation> */
    private array $operations;

    private SchemaDialect $dialect;

    /**
     * @param array<string, mixed> $document
     */
    private function __construct(private array $document)
    {
        $version = $this->document['openapi'] ?? null;
        if (!is_string($version) || !preg_match('/^3\.(0|1)\.[0-9]+$/', $version)) {
            throw UnsupportedVersion::forVersion(is_string($version) ? $version : 'missing');
        }
        $this->dialect = str_starts_with($version, '3.0.') ? SchemaDialect::OpenApi30 : SchemaDialect::OpenApi31;
        $this->assertDocumentDialect($this->document, $this->dialect);
        if (!isset($this->document['paths']) || !is_array($this->document['paths']) || $this->document['paths'] === []) {
            throw new InvalidContract('OpenAPI document must contain a non-empty paths object');
        }

        $resolver = new JsonPointerResolver($this->document);
        $rootServers = $this->serverBases($this->document['servers'] ?? null);
        $securitySchemes = $this->securitySchemes($this->document['components'] ?? null);
        $rootSecurity = array_key_exists('security', $this->document)
            ? $this->securityRequirements($this->document['security'], $securitySchemes)
            : [];
        $operations = [];
        $templates = [];
        $paths = $this->document['paths'];
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
                $operations[$key] = new Operation(
                    key: $key,
                    operationId: $operationId,
                    method: strtoupper($method),
                    path: $pathString,
                    parameters: $this->parameters($pathParameters, is_array($rawParameters) ? $rawParameters : [], $resolver),
                    requestBody: $this->resolvedObject($raw['requestBody'] ?? null, $resolver),
                    responses: $this->resolvedResponses($raw['responses'] ?? null, $resolver),
                    serverBases: $this->serverBases($raw['servers'] ?? null, $pathServers),
                    security: array_key_exists('security', $raw)
                        ? $this->securityRequirements($raw['security'], $securitySchemes)
                        : $rootSecurity,
                );
            }
        }
        $this->operations = array_values($operations);
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        return new self($document);
    }

    public static function fromJson(string $json, string $source = 'openapi.json'): self
    {
        if (strlen($json) > self::MAX_DOCUMENT_BYTES) {
            throw new InvalidContract(sprintf('OpenAPI document "%s" exceeds %d bytes', $source, self::MAX_DOCUMENT_BYTES));
        }

        try {
            $document = json_decode($json, associative: true, depth: 64, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidContract(sprintf('OpenAPI document "%s" is not valid JSON', $source), $exception->getCode(), previous: $exception);
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new InvalidContract('OpenAPI document must decode to an object');
        }

        /** @var array<string, mixed> $document */
        return new self($document);
    }

    public static function fromFile(string $path): self
    {
        $size = @filesize($path);
        if (is_int($size) && $size > self::MAX_DOCUMENT_BYTES) {
            throw new InvalidContract(sprintf('OpenAPI document "%s" exceeds %d bytes', $path, self::MAX_DOCUMENT_BYTES));
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new InvalidContract(sprintf('OpenAPI document "%s" is not readable', $path));
        }
        if (strlen($contents) > self::MAX_DOCUMENT_BYTES) {
            throw new InvalidContract(sprintf('OpenAPI document "%s" exceeds %d bytes', $path, self::MAX_DOCUMENT_BYTES));
        }
        if (str_ends_with(strtolower($path), '.yaml') || str_ends_with(strtolower($path), '.yml')) {
            $yamlClass = 'Symfony\\Component\\Yaml\\' . 'Yaml';
            if (!class_exists($yamlClass)) {
                throw new InvalidContract('YAML loading requires symfony/yaml');
            }
            $document = call_user_func([$yamlClass, 'parse'], $contents);
            if (!is_array($document) || array_is_list($document)) {
                throw new InvalidContract('OpenAPI YAML document must decode to an object');
            }

            /** @var array<string, mixed> $document */
            return new self($document);
        }

        return self::fromJson($contents, $path);
    }

    /** @return list<Operation> */
    public function operations(): array
    {
        return $this->operations;
    }

    public function operation(string $key): Operation
    {
        foreach ($this->operations as $operation) {
            if ($operation->key === $key) {
                return $operation;
            }
        }

        throw new UnknownOperation(sprintf('Operation "%s" is not present in the OpenAPI document', $key));
    }

    public function match(RequestInterface $request): ?MatchedOperation
    {
        $method = strtoupper($request->getMethod());
        $path = parse_url((string) $request->getUri(), PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        $candidates = [];
        foreach ($this->operations as $operation) {
            if ($operation->method !== $method) {
                continue;
            }
            foreach ($operation->serverBases as $baseIndex => $base) {
                // Bases from serverBases() are already '/'-canonical: rtrimmed or the bare '/'.
                $route = $base === '/' ? $operation->path : $base . $operation->path;
                $matched = $this->matchPath($route, $path);
                if ($matched !== null) {
                    $candidates[] = [$operation, $matched, substr_count($operation->path, '{'), strlen($route), $route, $baseIndex];
                }
            }
        }
        /** @var list<array{0: Operation, 1: array<string, string>, 2: int, 3: int, 4: string, 5: int}> $candidates */
        usort($candidates, static function (array $a, array $b): int {
            $aRoute = $a[4] ?? null;
            $bRoute = $b[4] ?? null;
            $aOperation = $a[0] ?? null;
            $bOperation = $b[0] ?? null;
            if (!is_string($aRoute) || !is_string($bRoute) || !$aOperation instanceof Operation || !$bOperation instanceof Operation) {
                throw new \LogicException('Operation match candidate has an invalid shape');
            }

            return ($a[2] <=> $b[2])
                ?: ($a[5] <=> $b[5])
                ?: ($b[3] <=> $a[3])
                ?: strcmp($aRoute, $bRoute)
                ?: strcmp($aOperation->key, $bOperation->key);
        });
        if ($candidates === []) {
            return null;
        }

        return new MatchedOperation($candidates[0][0], $candidates[0][1]);
    }

    public function requireMatch(RequestInterface $request): MatchedOperation
    {
        return $this->match($request) ?? throw new UnknownOperation(sprintf('No operation matches %s %s', $request->getMethod(), (string) $request->getUri()));
    }

    public function validateRequest(RequestInterface $request): ValidationResult
    {
        $matched = $this->match($request);
        if (!$matched instanceof MatchedOperation) {
            return $this->unknownOperationResult($request);
        }

        return (new RequestValidator())->validate($matched, $request, $this->dialect);
    }

    public function validateExchange(RequestInterface $request, ResponseInterface $response): ValidationResult
    {
        $matched = $this->match($request);
        if (!$matched instanceof MatchedOperation) {
            return $this->unknownOperationResult($request);
        }

        $requestResult = (new RequestValidator())->validate($matched, $request, $this->dialect);
        $responseResult = (new ResponseValidator())->validate($matched, $response, $this->dialect);

        return new ValidationResult([...$requestResult->violations, ...$responseResult->violations]);
    }

    private function unknownOperationResult(RequestInterface $request): ValidationResult
    {
        return new ValidationResult([new Violation(
            code: 'request.operation.unknown',
            operation: 'unknown',
            location: 'request',
            instancePath: (string) $request->getUri(),
            specPointer: '/paths',
            expected: 'declared operation',
            actual: strtoupper($request->getMethod()) . ' ' . $request->getUri()->getPath(),
            message: sprintf('No operation matches %s %s', strtoupper($request->getMethod()), $request->getUri()->getPath()),
        )]);
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
                'required' => $in === 'path' || (($raw['required'] ?? false) === true),
                'style' => $style,
                'explode' => is_bool($explodeValue) ? $explodeValue : $style === 'form',
                'allowReserved' => ($raw['allowReserved'] ?? false) === true,
                'schema' => $schema,
            ];
        }

        return array_values($result);
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
            foreach ($requirement as $name => $scopes) {
                if (!is_string($name) || $name === '' || !in_array($name, $securitySchemes, strict: true)) {
                    throw new InvalidContract(sprintf('OpenAPI security requirement references unknown scheme "%s"', (string) $name));
                }
                if (!is_array($scopes) || !array_is_list($scopes)) {
                    throw new InvalidContract(sprintf('OpenAPI security scopes for "%s" must be a list', $name));
                }
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

    /** @return array<string, string>|null */
    private function matchPath(string $route, string $requestPath): ?array
    {
        $routeParts = explode('/', trim($route, '/'));
        $requestParts = explode('/', trim($requestPath, '/'));
        if (count($routeParts) !== count($requestParts)) {
            return null;
        }
        $params = [];
        foreach ($routeParts as $index => $part) {
            $rawRequestPart = $requestParts[$index];
            $requestPart = rawurldecode($rawRequestPart);
            if (str_contains($requestPart, '/') || str_contains($requestPart, '\\')) {
                return null;
            }
            if (preg_match('/^\{([^{}]+)\}$/', $part, $match) === 1) {
                $params[$match[1]] = $rawRequestPart;
                continue;
            }
            if ($part !== $requestPart) {
                return null;
            }
        }

        return $params;
    }
}
