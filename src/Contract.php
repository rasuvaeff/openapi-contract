<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\OpenApiContract\Internal\Compilation\DocumentCompiler;
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
    public const int MAX_MESSAGE_BODY_BYTES = 1024 * 1024;

    /**
     * @param list<Operation> $operations
     */
    private function __construct(
        private SchemaDialect $dialect,
        private array $operations,
    ) {}

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        $compiled = (new DocumentCompiler())->compile($document);

        return new self($compiled->dialect, $compiled->operations);
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
        return self::fromArray($document);
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
            return self::fromArray($document);
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

    public function validateResponse(string $operationKey, ResponseInterface $response): ValidationResult
    {
        try {
            $operation = $this->operation($operationKey);
        } catch (UnknownOperation) {
            return new ValidationResult([new Violation(
                code: 'response.operation.unknown',
                operation: $operationKey,
                location: 'response',
                instancePath: '$',
                specPointer: '/paths',
                expected: 'declared operation',
                actual: $operationKey,
                message: sprintf('Operation "%s" is not present in the OpenAPI document', $operationKey),
            )]);
        }

        return (new ResponseValidator())->validate(
            new MatchedOperation($operation, []),
            $response,
            $this->dialect,
        );
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
