<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Internal\Compilation\SecuritySchemeCompiler;
use Rasuvaeff\OpenApiContract\InvalidContract;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Contract::class)]
#[Covers(SecuritySchemeCompiler::class)]
final class SecuritySchemeCompilerTest
{
    public function compilesEveryTypeIntoItsFunctionalFields(): void
    {
        $contract = $this->contract(['securitySchemes' => [
            'key' => ['type' => 'apiKey', 'name' => 'X-Api-Key', 'in' => 'header', 'description' => 'dropped', 'x-vendor' => 'dropped'],
            'queryKey' => ['type' => 'apiKey', 'name' => 'api_key', 'in' => 'query'],
            'session' => ['type' => 'apiKey', 'name' => 'sid', 'in' => 'cookie'],
            'basic' => ['type' => 'http', 'scheme' => 'basic'],
            'bearer' => ['type' => 'http', 'scheme' => 'Bearer', 'bearerFormat' => 'JWT'],
            'mtls' => ['type' => 'mutualTLS'],
            'oidc' => ['type' => 'openIdConnect', 'openIdConnectUrl' => 'https://id.example.test/.well-known/openid-configuration'],
            'oauth' => ['type' => 'oauth2', 'flows' => [
                'x-custom' => 'ignored',
                'implicit' => ['authorizationUrl' => 'https://id.example.test/auth', 'scopes' => ['read' => 'Read']],
                'password' => ['tokenUrl' => 'https://id.example.test/token', 'scopes' => []],
                'clientCredentials' => ['tokenUrl' => 'https://id.example.test/token', 'refreshUrl' => 'https://id.example.test/refresh', 'scopes' => ['write' => 'Write']],
                'authorizationCode' => ['authorizationUrl' => 'https://id.example.test/auth', 'tokenUrl' => 'https://id.example.test/token', 'scopes' => ['read' => 'Read', 'write' => 'Write']],
            ]],
            'referenced' => ['$ref' => '#/components/x-schemes/referenced'],
        ], 'x-schemes' => ['referenced' => ['type' => 'http', 'scheme' => 'digest']]]);

        Assert::same($contract->securitySchemes(), [
            'key' => ['type' => 'apiKey', 'name' => 'X-Api-Key', 'in' => 'header'],
            'queryKey' => ['type' => 'apiKey', 'name' => 'api_key', 'in' => 'query'],
            'session' => ['type' => 'apiKey', 'name' => 'sid', 'in' => 'cookie'],
            'basic' => ['type' => 'http', 'scheme' => 'basic'],
            'bearer' => ['type' => 'http', 'scheme' => 'Bearer', 'bearerFormat' => 'JWT'],
            'mtls' => ['type' => 'mutualTLS'],
            'oidc' => ['type' => 'openIdConnect', 'openIdConnectUrl' => 'https://id.example.test/.well-known/openid-configuration'],
            'oauth' => ['type' => 'oauth2', 'flows' => [
                'implicit' => ['scopes' => ['read' => 'Read'], 'authorizationUrl' => 'https://id.example.test/auth'],
                'password' => ['scopes' => [], 'tokenUrl' => 'https://id.example.test/token'],
                'clientCredentials' => ['scopes' => ['write' => 'Write'], 'tokenUrl' => 'https://id.example.test/token', 'refreshUrl' => 'https://id.example.test/refresh'],
                'authorizationCode' => ['scopes' => ['read' => 'Read', 'write' => 'Write'], 'authorizationUrl' => 'https://id.example.test/auth', 'tokenUrl' => 'https://id.example.test/token'],
            ]],
            'referenced' => ['type' => 'http', 'scheme' => 'digest'],
        ]);
    }

    public function compilesAnEmptyMapWithoutComponentsOrSchemes(): void
    {
        Assert::same($this->contract(null)->securitySchemes(), []);
        Assert::same($this->contract([])->securitySchemes(), []);
        Assert::same($this->contract(['securitySchemes' => []])->securitySchemes(), []);
        Assert::same($this->contract(['schemas' => ['Pet' => ['type' => 'object']]])->securitySchemes(), []);
    }

    public function keepsSchemeNamesAlignedWithRequirements(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.0.3',
            'components' => ['securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']]],
            'security' => [['bearer' => []]],
            'paths' => ['/h' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]],
        ]);

        Assert::same(array_keys($contract->securitySchemes()), ['bearer']);
        Assert::same($contract->operations()[0]->security, [['bearer' => []]]);
    }

    #[DataProvider('malformedSchemeProvider')]
    public function rejectsMalformedSchemes(mixed $components, string $message, string $version = '3.1.0'): void
    {
        try {
            Contract::fromArray([
                'openapi' => $version,
                'components' => $components,
                'paths' => ['/h' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]],
            ]);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), $message);
        }
    }

    public static function malformedSchemeProvider(): iterable
    {
        $schemes = static fn(mixed $scheme): array => ['securitySchemes' => ['api' => $scheme]];
        $oauth = static fn(array $flows): array => ['securitySchemes' => ['api' => ['type' => 'oauth2', 'flows' => $flows]]];

        yield 'components list' => [['a'], 'OpenAPI components must be an object'];
        yield 'components scalar' => ['x', 'OpenAPI components must be an object'];
        yield 'securitySchemes list' => [['securitySchemes' => ['api']], 'OpenAPI securitySchemes must be an object'];
        yield 'securitySchemes scalar' => [['securitySchemes' => 'x'], 'OpenAPI securitySchemes must be an object'];
        yield 'securitySchemes null' => [['securitySchemes' => null], 'OpenAPI securitySchemes must be an object'];
        yield 'scheme scalar' => [$schemes('x'), 'OpenAPI security scheme must be a named object'];
        yield 'scheme list' => [['securitySchemes' => ['api' => ['x']]], 'OpenAPI security scheme must be a named object'];
        yield 'scheme empty name' => [['securitySchemes' => ['' => ['type' => 'mutualTLS']]], 'OpenAPI security scheme must be a named object'];
        yield 'scheme integer name' => [['securitySchemes' => [7 => ['type' => 'mutualTLS']]], 'OpenAPI security scheme must be a named object'];
        yield 'missing type' => [$schemes([]), 'OpenAPI security scheme "api" must declare a type of apiKey, http, mutualTLS, oauth2, or openIdConnect'];
        yield 'unknown type' => [$schemes(['type' => 'basic']), 'OpenAPI security scheme "api" must declare a type of apiKey, http, mutualTLS, oauth2, or openIdConnect'];
        yield 'apiKey without name' => [$schemes(['type' => 'apiKey', 'in' => 'header']), 'OpenAPI security scheme "api" must declare a non-empty name'];
        yield 'apiKey empty name' => [$schemes(['type' => 'apiKey', 'name' => '', 'in' => 'header']), 'OpenAPI security scheme "api" must declare a non-empty name'];
        yield 'apiKey integer name' => [$schemes(['type' => 'apiKey', 'name' => 1, 'in' => 'header']), 'OpenAPI security scheme "api" must declare a non-empty name'];
        yield 'apiKey without in' => [$schemes(['type' => 'apiKey', 'name' => 'k']), 'OpenAPI security scheme "api" must declare in as query, header, or cookie'];
        yield 'apiKey unknown in' => [$schemes(['type' => 'apiKey', 'name' => 'k', 'in' => 'body']), 'OpenAPI security scheme "api" must declare in as query, header, or cookie'];
        yield 'http without scheme' => [$schemes(['type' => 'http']), 'OpenAPI security scheme "api" must declare a non-empty scheme'];
        yield 'http empty scheme' => [$schemes(['type' => 'http', 'scheme' => '']), 'OpenAPI security scheme "api" must declare a non-empty scheme'];
        yield 'http bearerFormat not a string' => [$schemes(['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => ['JWT']]), 'OpenAPI security scheme "api" must declare bearerFormat as a string'];
        yield 'mutualTLS on 3.0' => [$schemes(['type' => 'mutualTLS']), 'OpenAPI security scheme "api" of type "mutualTLS" requires OpenAPI 3.1', '3.0.3'];
        yield 'openIdConnect without url' => [$schemes(['type' => 'openIdConnect']), 'OpenAPI security scheme "api" must declare a non-empty openIdConnectUrl'];
        yield 'oauth2 without flows' => [$schemes(['type' => 'oauth2']), 'OpenAPI security scheme "api" must declare flows as an object'];
        yield 'oauth2 flows list' => [$oauth(['implicit']), 'OpenAPI security scheme "api" must declare flows as an object'];
        yield 'oauth2 unknown flow' => [$oauth(['device' => []]), 'OpenAPI security scheme "api" declares an unsupported flow "device"'];
        yield 'oauth2 integer flow name' => [$oauth([0 => [], 'implicit' => []]), 'OpenAPI security scheme "api" declares an unsupported flow "0"'];
        yield 'oauth2 flow scalar' => [$oauth(['implicit' => 'x']), 'OpenAPI security scheme "api" must declare flow "implicit" as an object'];
        yield 'oauth2 flow list' => [$oauth(['implicit' => ['x']]), 'OpenAPI security scheme "api" must declare flow "implicit" as an object'];
        yield 'implicit without authorizationUrl' => [$oauth(['implicit' => ['scopes' => []]]), 'OpenAPI security scheme "api" must declare a non-empty authorizationUrl for flow "implicit"'];
        yield 'password without tokenUrl' => [$oauth(['password' => ['scopes' => []]]), 'OpenAPI security scheme "api" must declare a non-empty tokenUrl for flow "password"'];
        yield 'clientCredentials without tokenUrl' => [$oauth(['clientCredentials' => ['scopes' => []]]), 'OpenAPI security scheme "api" must declare a non-empty tokenUrl for flow "clientCredentials"'];
        yield 'authorizationCode without authorizationUrl' => [$oauth(['authorizationCode' => ['tokenUrl' => 'https://t', 'scopes' => []]]), 'OpenAPI security scheme "api" must declare a non-empty authorizationUrl for flow "authorizationCode"'];
        yield 'authorizationCode without tokenUrl' => [$oauth(['authorizationCode' => ['authorizationUrl' => 'https://a', 'scopes' => []]]), 'OpenAPI security scheme "api" must declare a non-empty tokenUrl for flow "authorizationCode"'];
        yield 'empty url' => [$oauth(['password' => ['tokenUrl' => '', 'scopes' => []]]), 'OpenAPI security scheme "api" must declare a non-empty tokenUrl for flow "password"'];
        yield 'empty refreshUrl' => [$oauth(['password' => ['tokenUrl' => 'https://t', 'refreshUrl' => '', 'scopes' => []]]), 'OpenAPI security scheme "api" must declare a non-empty refreshUrl for flow "password"'];
        yield 'flow without scopes' => [$oauth(['password' => ['tokenUrl' => 'https://t']]), 'OpenAPI security scheme "api" must declare scopes for flow "password" as an object'];
        yield 'scopes list' => [$oauth(['password' => ['tokenUrl' => 'https://t', 'scopes' => ['read']]]), 'OpenAPI security scheme "api" must declare scopes for flow "password" as an object'];
        yield 'scope description not a string' => [$oauth(['password' => ['tokenUrl' => 'https://t', 'scopes' => ['read' => 1]]]), 'OpenAPI security scheme "api" must map every scope of flow "password" to a string description'];
        yield 'scope integer name' => [$oauth(['password' => ['tokenUrl' => 'https://t', 'scopes' => ['read' => 'Read', 0 => 'zero']]]), 'OpenAPI security scheme "api" must map every scope of flow "password" to a string description'];
        yield 'unresolvable reference' => [['securitySchemes' => ['api' => ['$ref' => '#/components/securitySchemes/missing']]], 'Unresolvable $ref "#/components/securitySchemes/missing" in OpenAPI document'];
    }

    private function contract(mixed $components): Contract
    {
        $document = ['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]]];
        if ($components !== null) {
            $document['components'] = $components;
        }

        return Contract::fromArray($document);
    }
}
