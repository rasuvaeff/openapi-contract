<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\OpenApiContract\Internal\Compilation\DocumentCompiler;
use Rasuvaeff\OpenApiContract\Internal\Reference\DocumentGraph;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaDialect;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaValidator;
use Rasuvaeff\OpenApiContract\Internal\Validation\RequestValidator;
use Rasuvaeff\OpenApiContract\Internal\Validation\ResponseValidator;

/**
 * Compiled OpenAPI 3.0/3.1 contract.
 *
 * Security Scheme Objects are compiled into the typed map returned by
 * {@see securitySchemes()}: the keys present besides `type` are exactly the
 * ones the scheme type defines, descriptions and extensions are dropped, and
 * a scheme missing a required field fails closed at compile time.
 *
 * @psalm-type CompiledOAuthFlow = array{
 *     authorizationUrl?: non-empty-string,
 *     tokenUrl?: non-empty-string,
 *     refreshUrl?: non-empty-string,
 *     scopes: array<string, string>,
 * }
 * @psalm-type CompiledSecurityScheme = array{
 *     type: 'apiKey'|'http'|'mutualTLS'|'oauth2'|'openIdConnect',
 *     name?: non-empty-string,
 *     in?: 'query'|'header'|'cookie',
 *     scheme?: non-empty-string,
 *     bearerFormat?: string,
 *     flows?: array<'authorizationCode'|'clientCredentials'|'implicit'|'password', CompiledOAuthFlow>,
 *     openIdConnectUrl?: non-empty-string,
 * }
 *
 * @api
 */
final readonly class Contract
{
    public const int MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;
    public const int MAX_MESSAGE_BODY_BYTES = 1024 * 1024;

    private RequestValidator $requests;

    private ResponseValidator $responses;

    /**
     * @param list<Operation> $operations
     * @param array<string, CompiledSecurityScheme> $securitySchemes
     */
    private function __construct(
        private SchemaDialect $dialect,
        private array $operations,
        private array $securitySchemes,
    ) {
        // One schema validator for the contract, so the compilation of a
        // schema is paid once and not once per validated message. Both
        // directions share it: a request and a response schema differ by the
        // direction the cache key already carries.
        $schemas = new SchemaValidator();
        $this->requests = new RequestValidator($schemas);
        $this->responses = new ResponseValidator($schemas);
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        $compiled = (new DocumentCompiler())->compile($document);

        return new self($compiled->dialect, $compiled->operations, $compiled->securitySchemes);
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

    /**
     * Unlike {@see fromArray()} and {@see fromJson()}, a file-loaded document
     * may reference sibling files with relative $refs; every referenced file
     * must stay inside the entry file's directory tree.
     */
    public static function fromFile(string $path): self
    {
        $graph = DocumentGraph::open($path);
        $compiled = (new DocumentCompiler())->compile($graph->entryDocument(), $graph);

        return new self($compiled->dialect, $compiled->operations, $compiled->securitySchemes);
    }

    /** @return list<Operation> */
    public function operations(): array
    {
        return $this->operations;
    }

    /**
     * The compiled `components.securitySchemes`, keyed by the name that
     * `Operation::$security` requirements refer to.
     *
     * @return array<string, CompiledSecurityScheme>
     */
    public function securitySchemes(): array
    {
        return $this->securitySchemes;
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
        return $this->matchWithDiagnostics($request)[0];
    }

    /**
     * @return array{0: ?MatchedOperation, 1: bool} the match, and whether some
     *         operation path matched only under a server whose authority the
     *         request URI contradicts
     */
    private function matchWithDiagnostics(RequestInterface $request): array
    {
        $method = strtoupper($request->getMethod());
        $path = parse_url((string) $request->getUri(), PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        $scheme = strtolower($request->getUri()->getScheme());
        $host = strtolower($request->getUri()->getHost());
        $port = $request->getUri()->getPort() ?? $this->defaultPort($scheme);
        $serverMismatch = false;
        $candidates = [];
        foreach ($this->operations as $operation) {
            if ($operation->method !== $method) {
                continue;
            }
            foreach ($operation->servers as $baseIndex => $server) {
                $base = $server['base'];
                // Bases are '/'-canonical at compile time: rtrimmed or the bare '/'.
                $route = $base === '/' ? $operation->path : $base . $operation->path;
                $matched = $this->matchPath($route, $path);
                if ($matched === null) {
                    continue;
                }
                if (!$this->authorityMatches($server, $scheme, $host, $port)) {
                    $serverMismatch = true;

                    continue;
                }
                $candidates[] = [$operation, $matched, substr_count($operation->path, '{'), strlen($route), $route, $baseIndex];
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
            return [null, $serverMismatch];
        }

        return [new MatchedOperation($candidates[0][0], $candidates[0][1]), $serverMismatch];
    }

    /**
     * An absolute server constrains only the URI components the request
     * actually carries: a relative request URI stays host-agnostic, while a
     * request with an authority must agree on normalized scheme, host, and
     * effective port.
     *
     * @param array{scheme: null|non-empty-string, host: null|non-empty-string, port: null|int, base: non-empty-string} $server
     */
    private function authorityMatches(array $server, string $scheme, string $host, ?int $port): bool
    {
        if ($server['host'] === null) {
            return true;
        }
        if ($host !== '' && $host !== $server['host']) {
            return false;
        }
        // A compiled absolute server always carries a scheme with its host.
        if ($scheme !== '' && $scheme !== $server['scheme']) {
            return false;
        }
        $serverPort = $server['port'] ?? $this->defaultPort($server['scheme'] ?? '');

        return $port === null || $serverPort === null || $port === $serverPort;
    }

    private function defaultPort(string $scheme): ?int
    {
        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }

    public function requireMatch(RequestInterface $request): MatchedOperation
    {
        return $this->match($request) ?? throw new UnknownOperation(sprintf('No operation matches %s %s', $request->getMethod(), (string) $request->getUri()));
    }

    public function validateRequest(RequestInterface $request): ValidationResult
    {
        [$matched, $serverMismatch] = $this->matchWithDiagnostics($request);
        if (!$matched instanceof MatchedOperation) {
            return $this->unmatchedResult($request, $serverMismatch);
        }

        return $this->requests->validate($matched, $request, $this->dialect);
    }

    public function validateExchange(RequestInterface $request, ResponseInterface $response): ValidationResult
    {
        [$matched, $serverMismatch] = $this->matchWithDiagnostics($request);
        if (!$matched instanceof MatchedOperation) {
            return $this->unmatchedResult($request, $serverMismatch);
        }

        $requestResult = $this->requests->validate($matched, $request, $this->dialect);
        $responseResult = $this->responses->validate($matched, $response, $this->dialect);

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

        return $this->responses->validate(
            new MatchedOperation($operation, []),
            $response,
            $this->dialect,
        );
    }

    private function unmatchedResult(RequestInterface $request, bool $serverMismatch): ValidationResult
    {
        // The path, never the whole URI: a query string is where an API key
        // travels, and this diagnostic is rendered verbatim into the message
        // that `ContractViolation` carries and an application logs. The
        // redaction that guards `actual` cannot help a field printed beside
        // it — it used to find `api_key` *inside* this very value and redact
        // the line below while printing this one.
        $path = $request->getUri()->getPath();
        if ($serverMismatch) {
            $authority = $request->getUri()->getScheme() . '://' . $request->getUri()->getAuthority();

            return new ValidationResult([new Violation(
                code: 'request.server.mismatch',
                operation: 'unknown',
                location: 'request',
                instancePath: $path,
                specPointer: '/servers',
                expected: 'declared server',
                actual: $authority,
                message: sprintf(
                    'Path %s is declared, but no server of its operations matches %s',
                    $path,
                    $authority,
                ),
            )]);
        }

        return new ValidationResult([new Violation(
            code: 'request.operation.unknown',
            operation: 'unknown',
            location: 'request',
            instancePath: $path,
            specPointer: '/paths',
            expected: 'declared operation',
            actual: strtoupper($request->getMethod()) . ' ' . $path,
            message: sprintf('No operation matches %s %s', strtoupper($request->getMethod()), $path),
        )]);
    }

    /**
     * @return array<string, string>|null
     */
    private function matchPath(string $route, string $requestPath): ?array
    {
        $routeParts = $this->segments($route);
        $requestParts = $this->segments($requestPath);
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
            if (str_contains($part, '{')) {
                $captured = $this->matchTemplateSegment($part, $rawRequestPart);
                if ($captured === null) {
                    return null;
                }
                $params = [...$params, ...$captured];
                continue;
            }
            if ($part !== $requestPart) {
                return null;
            }
        }

        return $params;
    }

    /**
     * The segments of a path, with a trailing slash kept as the empty segment
     * it is: RFC 3986 makes `/pets` and `/pets/` different resources, and so
     * does every router the application sits behind. Trimming both ends — as
     * this did — let a document declaring both compile and then answer both
     * requests with whichever route sorted first, leaving the other operation
     * unreachable. Nothing is stripped: both sides are split the same way, so
     * the leading empty segment cancels out and the trailing one does not.
     *
     * @return list<string>
     */
    private function segments(string $path): array
    {
        return explode('/', $path);
    }

    /**
     * One path segment that mixes literals with placeholders — `/report.{format}`,
     * `/v{version}/…` — which OpenAPI allows and this package used to compile
     * and then never match, so the operation was unreachable and a request
     * that literally equalled the template was blamed for a missing parameter.
     *
     * The literal runs are matched as written: a segment whose literal part
     * arrives percent-encoded does not match, which is the same trade the
     * whole-segment form makes in reverse and is invisible for every template
     * shape a document actually uses.
     *
     * @return array<string, string>|null
     */
    private function matchTemplateSegment(string $template, string $rawRequestPart): ?array
    {
        $names = [];
        $pattern = '';
        $offset = 0;
        while (preg_match('/\{([^{}]+)\}/', $template, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            [$placeholder, $position] = $match[0];
            $pattern .= preg_quote(substr($template, $offset, $position - $offset), '~') . '([^/]+)';
            $names[] = $match[1][0];
            $offset = $position + strlen($placeholder);
        }
        $pattern .= preg_quote(substr($template, $offset), '~');
        if (preg_match('~^' . $pattern . '\z~', $rawRequestPart, $captured) !== 1) {
            return null;
        }

        $params = [];
        foreach ($names as $index => $name) {
            $params[$name] = $captured[$index + 1];
        }

        return $params;
    }
}
