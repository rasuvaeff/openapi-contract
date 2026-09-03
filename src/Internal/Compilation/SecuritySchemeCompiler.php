<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Compilation;

use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Internal\Reference\JsonPointerResolver;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaDialect;
use Rasuvaeff\OpenApiContract\InvalidContract;

/**
 * Compiles `components.securitySchemes` into the typed map exposed by
 * `Contract::securitySchemes()`: every Security Scheme Object is resolved,
 * its type-specific required fields are checked, and only the functional
 * fields survive (descriptions and extensions do not).
 *
 * @psalm-import-type CompiledSecurityScheme from Contract
 * @psalm-import-type CompiledOAuthFlow from Contract
 *
 * @internal
 */
final readonly class SecuritySchemeCompiler
{
    private const array API_KEY_LOCATIONS = ['query', 'header', 'cookie'];

    /** @var array<string, list<string>> flow name → URLs it must declare */
    private const array FLOW_URLS = [
        'implicit' => ['authorizationUrl'],
        'password' => ['tokenUrl'],
        'clientCredentials' => ['tokenUrl'],
        'authorizationCode' => ['authorizationUrl', 'tokenUrl'],
    ];

    /** @return array<string, CompiledSecurityScheme> */
    public function compile(mixed $components, SchemaDialect $dialect, JsonPointerResolver $resolver): array
    {
        if ($components === null) {
            return [];
        }
        $components = $this->object($components) ?? throw new InvalidContract('OpenAPI components must be an object');
        $schemesValue = $this->object(array_key_exists('securitySchemes', $components) ? $components['securitySchemes'] : [])
            ?? throw new InvalidContract('OpenAPI securitySchemes must be an object');
        $schemes = [];
        /** @var mixed $schemeValue */
        foreach ($schemesValue as $name => $schemeValue) {
            $scheme = $this->object($schemeValue);
            if (!is_string($name) || $name === '' || $scheme === null) {
                throw new InvalidContract('OpenAPI security scheme must be a named object');
            }
            $schemes[$name] = $this->scheme($name, $resolver->resolve($scheme), $dialect);
        }

        return $schemes;
    }

    /** @return array<array-key, mixed>|null the value when it is a JSON object, `null` otherwise */
    private function object(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        return $value !== [] && array_is_list($value) ? null : $value;
    }

    /**
     * @param non-empty-string $name
     * @param array<array-key, mixed> $scheme
     * @return CompiledSecurityScheme
     */
    private function scheme(string $name, array $scheme, SchemaDialect $dialect): array
    {
        /** @var mixed $type */
        $type = $scheme['type'] ?? null;

        return match ($type) {
            'apiKey' => [
                'type' => 'apiKey',
                'name' => $this->requiredString($name, $scheme, 'name'),
                'in' => $this->apiKeyLocation($name, $scheme),
            ],
            'http' => $this->http($name, $scheme),
            'mutualTLS' => $dialect === SchemaDialect::OpenApi31
                ? ['type' => 'mutualTLS']
                : throw new InvalidContract(sprintf('OpenAPI security scheme "%s" of type "mutualTLS" requires OpenAPI 3.1', $name)),
            'oauth2' => ['type' => 'oauth2', 'flows' => $this->flows($name, $scheme)],
            'openIdConnect' => ['type' => 'openIdConnect', 'openIdConnectUrl' => $this->requiredString($name, $scheme, 'openIdConnectUrl')],
            default => throw new InvalidContract(sprintf(
                'OpenAPI security scheme "%s" must declare a type of apiKey, http, mutualTLS, oauth2, or openIdConnect',
                $name,
            )),
        };
    }

    /**
     * @param non-empty-string $name
     * @param array<array-key, mixed> $scheme
     * @return array{type: 'http', scheme: non-empty-string, bearerFormat?: string}
     */
    private function http(string $name, array $scheme): array
    {
        $compiled = ['type' => 'http', 'scheme' => $this->requiredString($name, $scheme, 'scheme')];
        if (!array_key_exists('bearerFormat', $scheme)) {
            return $compiled;
        }
        /** @var mixed $bearerFormat */
        $bearerFormat = $scheme['bearerFormat'];
        if (!is_string($bearerFormat)) {
            throw new InvalidContract(sprintf('OpenAPI security scheme "%s" must declare bearerFormat as a string', $name));
        }

        return [...$compiled, 'bearerFormat' => $bearerFormat];
    }

    /**
     * @param non-empty-string $name
     * @param array<array-key, mixed> $scheme
     * @return 'query'|'header'|'cookie'
     */
    private function apiKeyLocation(string $name, array $scheme): string
    {
        /** @var mixed $in */
        $in = $scheme['in'] ?? null;
        if (!is_string($in) || !in_array($in, self::API_KEY_LOCATIONS, strict: true)) {
            throw new InvalidContract(sprintf('OpenAPI security scheme "%s" must declare in as query, header, or cookie', $name));
        }

        return $in;
    }

    /**
     * @param non-empty-string $name
     * @param array<array-key, mixed> $scheme
     * @return array<'authorizationCode'|'clientCredentials'|'implicit'|'password', CompiledOAuthFlow>
     */
    private function flows(string $name, array $scheme): array
    {
        $flowsValue = $this->object($scheme['flows'] ?? null)
            ?? throw new InvalidContract(sprintf('OpenAPI security scheme "%s" must declare flows as an object', $name));
        $flows = [];
        /** @var mixed $flowValue */
        foreach ($flowsValue as $flowName => $flowValue) {
            if (is_string($flowName) && str_starts_with($flowName, 'x-')) {
                continue;
            }
            if (!is_string($flowName) || !array_key_exists($flowName, self::FLOW_URLS)) {
                throw new InvalidContract(sprintf('OpenAPI security scheme "%s" declares an unsupported flow "%s"', $name, (string) $flowName));
            }
            $flow = $this->object($flowValue)
                ?? throw new InvalidContract(sprintf('OpenAPI security scheme "%s" must declare flow "%s" as an object', $name, $flowName));
            $flows[$flowName] = $this->flow($name, $flowName, $flow);
        }

        return $flows;
    }

    /**
     * @param non-empty-string $name
     * @param 'authorizationCode'|'clientCredentials'|'implicit'|'password' $flowName
     * @param array<array-key, mixed> $flow
     * @return CompiledOAuthFlow
     */
    private function flow(string $name, string $flowName, array $flow): array
    {
        $required = self::FLOW_URLS[$flowName];
        $compiled = ['scopes' => $this->scopes($name, $flowName, $flow)];
        if (in_array('authorizationUrl', $required, strict: true)) {
            $compiled['authorizationUrl'] = $this->url($name, $flowName, $flow, 'authorizationUrl');
        }
        if (in_array('tokenUrl', $required, strict: true)) {
            $compiled['tokenUrl'] = $this->url($name, $flowName, $flow, 'tokenUrl');
        }
        if (array_key_exists('refreshUrl', $flow)) {
            $compiled['refreshUrl'] = $this->url($name, $flowName, $flow, 'refreshUrl');
        }

        return $compiled;
    }

    /**
     * @param non-empty-string $name
     * @param array<array-key, mixed> $flow
     * @return non-empty-string
     */
    private function url(string $name, string $flowName, array $flow, string $field): string
    {
        /** @var mixed $url */
        $url = $flow[$field] ?? null;
        if (!is_string($url) || $url === '') {
            throw new InvalidContract(sprintf('OpenAPI security scheme "%s" must declare a non-empty %s for flow "%s"', $name, $field, $flowName));
        }

        return $url;
    }

    /**
     * @param non-empty-string $name
     * @param array<array-key, mixed> $flow
     * @return array<string, string>
     */
    private function scopes(string $name, string $flowName, array $flow): array
    {
        $scopesValue = $this->object($flow['scopes'] ?? null)
            ?? throw new InvalidContract(sprintf('OpenAPI security scheme "%s" must declare scopes for flow "%s" as an object', $name, $flowName));
        $scopes = [];
        /** @var mixed $description */
        foreach ($scopesValue as $scope => $description) {
            if (!is_string($scope) || !is_string($description)) {
                throw new InvalidContract(sprintf('OpenAPI security scheme "%s" must map every scope of flow "%s" to a string description', $name, $flowName));
            }
            $scopes[$scope] = $description;
        }

        return $scopes;
    }

    /**
     * @param non-empty-string $name
     * @param array<array-key, mixed> $scheme
     * @return non-empty-string
     */
    private function requiredString(string $name, array $scheme, string $field): string
    {
        /** @var mixed $value */
        $value = $scheme[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidContract(sprintf('OpenAPI security scheme "%s" must declare a non-empty %s', $name, $field));
        }

        return $value;
    }
}
