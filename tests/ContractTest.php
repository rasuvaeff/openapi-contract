<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedDialect;
use Rasuvaeff\OpenApiContract\InvalidContract;
use Rasuvaeff\OpenApiContract\UnknownOperation;
use Rasuvaeff\OpenApiContract\UnsupportedSerialization;
use Rasuvaeff\OpenApiContract\UnsupportedVersion;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Contract::class)]
#[Covers(InvalidContract::class)]
#[Covers(UnknownOperation::class)]
#[Covers(UnsupportedSerialization::class)]
#[Covers(UnsupportedVersion::class)]
final class ContractTest
{
    public function matchesConcretePathBeforeTemplate(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => [
                '/pets/{id}' => ['get' => ['operationId' => 'byId', 'responses' => ['200' => []]]],
                '/pets/mine' => ['get' => ['operationId' => 'mine', 'responses' => ['200' => []]]],
            ],
        ]);

        $matched = $contract->requireMatch(new ServerRequest('GET', '/pets/mine'));

        Assert::same($matched->operation->operationId, 'mine');
        Assert::same($matched->pathParameters, []);
    }

    public function decodesPathParameterExactlyOnce(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.0.3',
            'paths' => ['/pets/{id}' => ['get' => ['operationId' => 'byId', 'responses' => ['200' => []]]]],
        ]);

        $matched = $contract->requireMatch(new ServerRequest('GET', '/pets/a%20b'));

        Assert::same($matched->pathParameters, ['id' => 'a%20b']);
        Assert::null($contract->match(new ServerRequest('POST', '/pets/a%20b')));
    }

    public function supportsServerBasePathAndOperationFallbackIdentity(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'servers' => [['url' => 'https://api.example.test/v1']],
            'paths' => ['/health' => ['get' => ['responses' => ['200' => []]]]],
        ]);

        $matched = $contract->requireMatch(new ServerRequest('GET', '/v1/health'));

        Assert::same($matched->operation->key, 'GET /health');
        Assert::null($matched->operation->operationId);
    }

    public function rejectsUnsupportedVersion(): void
    {
        Expect::exception(UnsupportedVersion::class);

        Contract::fromArray(['openapi' => '3.2.0', 'paths' => ['/x' => []]]);
    }

    public function rejectsVersionWithTrailingData(): void
    {
        Expect::exception(UnsupportedVersion::class);

        Contract::fromArray(['openapi' => '3.1.0-beta', 'paths' => ['/x' => []]]);
    }

    public function rejectsVersionWithLeadingData(): void
    {
        Expect::exception(UnsupportedVersion::class);

        Contract::fromArray(['openapi' => 'v3.1.0', 'paths' => ['/x' => []]]);
    }

    public function rejectsDuplicateOperationId(): void
    {
        Expect::exception(InvalidContract::class);

        Contract::fromArray([
            'openapi' => '3.0.0',
            'paths' => [
                '/a' => ['get' => ['operationId' => 'same', 'responses' => ['200' => []]]],
                '/b' => ['post' => ['operationId' => 'same', 'responses' => ['200' => []]]],
            ],
        ]);
    }

    public function rejectsAmbiguousTemplates(): void
    {
        Expect::exception(InvalidContract::class);

        Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => [
                '/pets/{id}' => ['get' => ['responses' => ['200' => []]]],
                '/pets/{name}' => ['get' => ['responses' => ['200' => []]]],
            ],
        ]);
    }

    public function resolvesPathItemReferences(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'components' => ['pathItems' => [
                'pet' => ['get' => ['operationId' => 'pet.get', 'responses' => ['200' => []]]],
            ]],
            'paths' => ['/pet' => ['$ref' => '#/components/pathItems/pet']],
        ]);

        Assert::same($contract->requireMatch(new ServerRequest('GET', '/pet'))->operation->operationId, 'pet.get');
    }

    public function rejectsMalformedPathEntries(): void
    {
        Expect::exception(InvalidContract::class);
        Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/broken' => 'not-an-object']]);
    }

    public function rejectsDecodedBackslashPathSegments(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets/{id}' => ['get' => ['responses' => ['200' => []]]]],
        ]);

        Assert::null($contract->match(new ServerRequest('GET', '/pets/%5C')));
    }

    public function rejectsUnsupportedParameterStyle(): void
    {
        Expect::exception(UnsupportedSerialization::class);

        Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets' => ['get' => [
                'parameters' => [['name' => 'filter', 'in' => 'query', 'style' => 'matrix']],
                'responses' => ['200' => []],
            ]]],
        ]);
    }

    public function requireMatchReportsUnknownOperation(): void
    {
        Expect::exception(UnknownOperation::class);

        Contract::fromArray([
            'openapi' => '3.0.0',
            'paths' => ['/x' => ['get' => ['responses' => ['200' => []]]]],
        ])
            ->requireMatch(new ServerRequest('GET', '/missing'));
    }

    public function rejectsEmptyResponsesObject(): void
    {
        Expect::exception(InvalidContract::class);

        Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/x' => ['get' => ['responses' => []]]],
        ]);
    }

    public function inheritsAndOverridesSecurityRequirements(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'components' => ['securitySchemes' => [
                'oauth' => ['type' => 'oauth2'],
                'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'],
            ]],
            'security' => [['oauth' => ['read']]],
            'paths' => [
                '/secure' => ['get' => ['operationId' => 'secure', 'responses' => ['200' => []]]],
                '/public' => ['get' => ['operationId' => 'public', 'security' => [], 'responses' => ['200' => []]]],
            ],
        ]);

        Assert::same($contract->operation('secure')->security, [['oauth' => ['read']]]);
        Assert::same($contract->operation('public')->security, []);
    }

    public function rejectsUnknownSecuritySchemeReferences(): void
    {
        Expect::exception(InvalidContract::class);

        Contract::fromArray([
            'openapi' => '3.1.0',
            'security' => [['missing' => []]],
            'paths' => ['/x' => ['get' => ['responses' => ['200' => []]]]],
        ]);
    }

    public function rejectsMalformedSecurityRequirement(): void
    {
        Expect::exception(InvalidContract::class);

        Contract::fromArray([
            'openapi' => '3.1.0',
            'components' => ['securitySchemes' => ['apiKey' => ['type' => 'apiKey']]],
            'security' => [['apiKey' => 'read']],
            'paths' => ['/x' => ['get' => ['responses' => ['200' => []]]]],
        ]);
    }

    public function rejectsNullSecurityFields(): void
    {
        Expect::exception(InvalidContract::class);

        Contract::fromArray([
            'openapi' => '3.1.0',
            'security' => null,
            'paths' => ['/x' => ['get' => ['responses' => ['200' => []]]]],
        ]);
    }

    public function rejectsNullSecuritySchemes(): void
    {
        Expect::exception(InvalidContract::class);

        Contract::fromArray([
            'openapi' => '3.1.0',
            'components' => ['securitySchemes' => null],
            'paths' => ['/x' => ['get' => ['responses' => ['200' => []]]]],
        ]);
    }

    public function rejectsMissingAndEmptyPaths(): void
    {
        Expect::exception(InvalidContract::class);

        Contract::fromArray(['openapi' => '3.1.0', 'paths' => []]);
    }

    public function rejectsInvalidPathAndOperationShapes(): void
    {
        Expect::exception(InvalidContract::class);

        Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['health' => []]]);
    }

    public function rejectsNonObjectOperation(): void
    {
        Expect::exception(InvalidContract::class);

        Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/health' => ['get' => 'invalid']]]);
    }

    public function rejectsInvalidOperationId(): void
    {
        Expect::exception(InvalidContract::class);

        Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/health' => ['get' => ['operationId' => 42, 'responses' => ['200' => []]]]]]);
    }

    public function rejectsInvalidServerDefinitions(): void
    {
        Expect::exception(InvalidContract::class);

        Contract::fromArray(['openapi' => '3.1.0', 'servers' => [['url' => 42]], 'paths' => ['/health' => ['get' => ['responses' => ['200' => []]]]]]);
    }

    public function rejectsInvalidComponentsAndSchemes(): void
    {
        Expect::exception(InvalidContract::class);

        Contract::fromArray(['openapi' => '3.1.0', 'components' => ['securitySchemes' => ['api']], 'paths' => ['/health' => ['get' => ['responses' => ['200' => []]]]]]);
    }

    public function rejectsMalformedResponseDefinitions(): void
    {
        Expect::exception(InvalidContract::class);

        Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/health' => ['get' => ['responses' => ['200' => 'invalid']]]]]);
    }

    public function rejectsMalformedParametersAndSchemas(): void
    {
        Expect::exception(InvalidContract::class);

        Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/health' => ['get' => [
            'parameters' => [['name' => '', 'in' => 'query', 'schema' => ['type' => 'string']]],
            'responses' => ['200' => []],
        ]]]]);
    }

    public function rejectsParameterContentSerialization(): void
    {
        Expect::exception(UnsupportedSerialization::class);

        Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/health' => ['get' => [
            'parameters' => [['name' => 'filter', 'in' => 'query', 'content' => ['application/json' => []]]],
            'responses' => ['200' => []],
        ]]]]);
    }

    public function rejectsUnsupportedJsonSchemaDialect(): void
    {
        Expect::exception(UnsupportedDialect::class);

        Contract::fromArray(['openapi' => '3.1.0', 'jsonSchemaDialect' => 'https://example.test/dialect', 'paths' => ['/health' => ['get' => ['responses' => ['200' => []]]]]]);
    }

    public function loadsJsonAndRejectsNonObjectDocuments(): void
    {
        $contract = Contract::fromJson('{"openapi":"3.1.0","paths":{"/health":{"get":{"responses":{"200":{}}}}}}');
        Assert::same($contract->operations()[0]->method, 'GET');

        Expect::exception(InvalidContract::class);
        Contract::fromJson('[]');
    }

    public function rejectsInvalidJsonDocument(): void
    {
        Expect::exception(InvalidContract::class);

        Contract::fromJson('{broken');
    }

    public function loadsJsonFromFileAndRejectsUnreadableFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'openapi-');
        if ($path === false) {
            throw new \RuntimeException('Unable to allocate temporary file');
        }
        file_put_contents($path, '{"openapi":"3.1.0","paths":{"/health":{"get":{"responses":{"200":{}}}}}}');

        try {
            Assert::same(Contract::fromFile($path)->operations()[0]->path, '/health');
        } finally {
            unlink($path);
        }

        Expect::exception(InvalidContract::class);
        Contract::fromFile('/path/that/does/not/exist.json');
    }
}
