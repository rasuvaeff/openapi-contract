<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Internal\Compilation\CompiledDocument;
use Rasuvaeff\OpenApiContract\Internal\Compilation\DocumentCompiler;
use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedDialect;
use Rasuvaeff\OpenApiContract\InvalidContract;
use Rasuvaeff\OpenApiContract\MatchedOperation;
use Rasuvaeff\OpenApiContract\UnknownOperation;
use Rasuvaeff\OpenApiContract\UnsupportedSerialization;
use Rasuvaeff\OpenApiContract\UnsupportedVersion;
use Rasuvaeff\OpenApiContract\Violation;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Contract::class)]
#[Covers(CompiledDocument::class)]
#[Covers(DocumentCompiler::class)]
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
                '/pets/{id}' => [
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true]],
                    'get' => ['operationId' => 'byId', 'responses' => ['200' => []]],
                ],
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
            'paths' => ['/pets/{id}' => [
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true]],
                'get' => ['operationId' => 'byId', 'responses' => ['200' => []]],
            ]],
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
                '/pets/{id}' => [
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true]],
                    'get' => ['responses' => ['200' => []]],
                ],
                '/pets/{name}' => [
                    'parameters' => [['name' => 'name', 'in' => 'path', 'required' => true]],
                    'get' => ['responses' => ['200' => []]],
                ],
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
            'paths' => ['/pets/{id}' => [
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true]],
                'get' => ['responses' => ['200' => []]],
            ]],
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
                'oauth' => ['type' => 'oauth2', 'flows' => ['implicit' => ['authorizationUrl' => 'https://id.example.test/auth', 'scopes' => ['read' => 'Read']]]],
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
            'components' => ['securitySchemes' => ['apiKey' => ['type' => 'apiKey', 'name' => 'k', 'in' => 'query']]],
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

    public function reportsTheRejectedVersionInTheMessage(): void
    {
        try {
            Contract::fromArray(['openapi' => '2.0.0', 'paths' => ['/x' => []]]);
            Assert::true(actual: false);
        } catch (UnsupportedVersion $exception) {
            Assert::same($exception->getMessage(), 'Unsupported OpenAPI version "2.0.0"; supported versions are 3.0.x and 3.1.x');
        }

        try {
            Contract::fromArray(['openapi' => 42, 'paths' => ['/x' => []]]);
            Assert::true(actual: false);
        } catch (UnsupportedVersion $exception) {
            Assert::same($exception->getMessage(), 'Unsupported OpenAPI version "missing"; supported versions are 3.0.x and 3.1.x');
        }
    }

    public function appliesTheDialectGateToTheDetectedVersion(): void
    {
        $document = [
            'openapi' => '3.1.0',
            'jsonSchemaDialect' => 'https://json-schema.org/draft/2020-12/schema',
            'paths' => ['/h' => ['get' => ['responses' => ['200' => []]]]],
        ];
        Assert::same(Contract::fromArray($document)->operations()[0]->method, 'GET');

        Expect::exception(UnsupportedDialect::class);
        $document['openapi'] = '3.0.3';
        Contract::fromArray($document);
    }

    public function rejectsANonArrayPathsValue(): void
    {
        Expect::exception(InvalidContract::class);

        Contract::fromArray(['openapi' => '3.1.0', 'paths' => 'invalid']);
    }

    public function reportsExactMessagesForMalformedOperations(): void
    {
        try {
            Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/x' => ['get' => 'invalid']]]);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'Operation at GET /x must be an object');
        }

        try {
            Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/x' => ['get' => ['operationId' => 42, 'responses' => ['200' => []]]]]]);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'Operation at GET /x has an invalid operationId');
        }
    }

    public function allowsTheSameTemplateShapeOnDifferentMethods(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => [
            '/pets/{id}' => [
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true]],
                'get' => ['responses' => ['200' => []]],
            ],
            '/pets/{petId}' => [
                'parameters' => [['name' => 'petId', 'in' => 'path', 'required' => true]],
                'post' => ['responses' => ['200' => []]],
            ],
        ]]);

        Assert::same(count($contract->operations()), 2);
    }

    public function reportsTheAmbiguousPathsWithTheUppercaseMethod(): void
    {
        try {
            Contract::fromArray(['openapi' => '3.1.0', 'paths' => [
                '/pets/{id}' => [
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true]],
                    'get' => ['responses' => ['200' => []]],
                ],
                '/pets/{name}' => [
                    'parameters' => [['name' => 'name', 'in' => 'path', 'required' => true]],
                    'get' => ['responses' => ['200' => []]],
                ],
            ]]);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'Ambiguous OpenAPI paths "/pets/{id}" and "/pets/{name}" for method GET');
        }
    }

    public function acceptsADocumentAtTheExactByteBudget(): void
    {
        $json = '{"openapi":"3.1.0","paths":{"/h":{"get":{"responses":{"200":{}}}}}}';
        $json .= str_repeat(' ', Contract::MAX_DOCUMENT_BYTES - strlen($json));
        Assert::same(strlen($json), Contract::MAX_DOCUMENT_BYTES);

        Assert::same(Contract::fromJson($json)->operations()[0]->path, '/h');

        $path = tempnam(sys_get_temp_dir(), 'openapi-');
        if ($path === false) {
            throw new \RuntimeException('Unable to allocate temporary file');
        }
        file_put_contents($path, $json);

        try {
            Assert::same(Contract::fromFile($path)->operations()[0]->path, '/h');
        } finally {
            unlink($path);
        }
    }

    public function honorsTheJsonDepthBudgetBoundary(): void
    {
        $nested = static fn(int $depth): string => sprintf(
            '{"openapi":"3.1.0","x-deep":%s1%s,"paths":{"/h":{"get":{"responses":{"200":{}}}}}}',
            str_repeat('[', $depth),
            str_repeat(']', $depth),
        );

        Assert::same(Contract::fromJson($nested(62))->operations()[0]->path, '/h');

        Expect::exception(InvalidContract::class);
        Contract::fromJson($nested(63));
    }

    public function reportsExactLoaderMessages(): void
    {
        try {
            Contract::fromJson('{broken');
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI document "openapi.json" is not valid JSON');
        }

        try {
            Contract::fromJson('[]');
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI document must decode to an object');
        }

        try {
            Contract::fromFile('/path/that/does/not/exist.json');
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI document "/path/that/does/not/exist.json" is not readable');
        }
    }

    public function rejectsAScalarJsonDocument(): void
    {
        Expect::exception(InvalidContract::class);

        Contract::fromJson('42');
    }

    public function loadsYamlDocumentsRegardlessOfExtensionCase(): void
    {
        $yaml = "openapi: '3.1.0'\npaths:\n  /h:\n    get:\n      responses:\n        '200': {}\n";
        foreach (['.yaml', '.YAML', '.yml', '.YML'] as $extension) {
            $base = tempnam(sys_get_temp_dir(), 'openapi-');
            if ($base === false) {
                throw new \RuntimeException('Unable to allocate temporary file');
            }
            $path = $base . $extension;
            file_put_contents($path, $yaml);

            try {
                Assert::same(Contract::fromFile($path)->operations()[0]->path, '/h');
            } finally {
                unlink($path);
                unlink($base);
            }
        }
    }

    public function keepsJsonFilesOnTheJsonLoadingPath(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'openapi-');
        if ($base === false) {
            throw new \RuntimeException('Unable to allocate temporary file');
        }
        $path = $base . '.json';
        file_put_contents($path, '{"openapi":"3.1.0","x-a":1,"x-a":2,"paths":{"/h":{"get":{"responses":{"200":{}}}}}}');

        try {
            Assert::same(Contract::fromFile($path)->operations()[0]->path, '/h');
        } finally {
            unlink($path);
            unlink($base);
        }
    }

    public function matchesLowercaseRequestMethodsAndBareHosts(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => [
            '/' => ['get' => ['responses' => ['200' => []]]],
            '/h' => ['get' => ['responses' => ['200' => []]]],
        ]]);

        $matched = $contract->match(new Request('get', 'http://api.example.com/h'));
        Assert::true($matched instanceof MatchedOperation && $matched->operation->path === '/h');

        $root = $contract->match(new Request('GET', 'http://api.example.com'));
        Assert::true($root instanceof MatchedOperation && $root->operation->path === '/');
    }

    public function scansPastOperationsWithOtherMethods(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => [
            '/a' => ['get' => ['responses' => ['200' => []]]],
            '/b' => ['post' => ['responses' => ['200' => []]]],
        ]]);

        $matched = $contract->match(new Request('POST', '/b'));
        Assert::true($matched instanceof MatchedOperation && $matched->operation->path === '/b');
    }

    public function matchesEveryDeclaredServerBase(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'servers' => [['url' => 'https://api.example.com/v1'], ['url' => 'https://api.example.com/v2']],
            'paths' => ['/x' => ['get' => ['responses' => ['200' => []]]]],
        ]);

        $matched = $contract->match(new Request('GET', 'https://api.example.com/v2/x'));
        Assert::true($matched instanceof MatchedOperation && $matched->operation->path === '/x');
    }

    public function reportsTheUnknownOperationWithNormalizedMethod(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => ['responses' => ['200' => []]]]]]);

        $result = $contract->validateRequest(new Request('post', '/none'));

        Assert::false($result->isValid());
        Assert::same($result->violations[0]->actual, 'POST /none');
        Assert::same($result->violations[0]->message, 'No operation matches POST /none');
    }

    public function exchangeKeepsRequestAndResponseViolations(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => [
            'parameters' => [['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']]],
            'responses' => ['200' => []],
        ]]]]);

        $result = $contract->validateExchange(new Request('GET', '/h'), new Response(299));

        Assert::same(
            array_map(static fn(Violation $violation): string => $violation->code, $result->violations),
            ['request.parameter.missing', 'response.status.mismatch'],
        );
    }

    public function compilesParametersIntoAnExactList(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => [
            'parameters' => [
                ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 2]],
                ['name' => 'q', 'in' => 'header', 'required' => true, 'allowReserved' => true, 'schema' => ['type' => 'string']],
                ['name' => 'X-Trace', 'in' => 'header', 'schema' => ['type' => 'string']],
                ['name' => 'x-trace', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
            ],
            'responses' => ['200' => []],
        ]]]]);

        Assert::same($contract->operations()[0]->parameters, [
            ['name' => 'q', 'in' => 'query', 'required' => false, 'style' => 'form', 'explode' => true, 'allowReserved' => false, 'schema' => ['type' => 'integer', 'minimum' => 2], 'specPointer' => '/paths/~1h/get/parameters/0'],
            ['name' => 'q', 'in' => 'header', 'required' => true, 'style' => 'simple', 'explode' => false, 'allowReserved' => true, 'schema' => ['type' => 'string'], 'specPointer' => '/paths/~1h/get/parameters/1'],
            ['name' => 'x-trace', 'in' => 'header', 'required' => true, 'style' => 'simple', 'explode' => false, 'allowReserved' => false, 'schema' => ['type' => 'string'], 'specPointer' => '/paths/~1h/get/parameters/3'],
        ]);
    }

    /**
     * These keywords do not merely add a check — they re-root or re-target
     * every `$ref` in the document. Passing them to the backend unhandled
     * would let it decide what the document means.
     */
    #[DataProvider('referenceIdentityKeywords')]
    public function rejectsReferenceIdentityKeywords(string $keyword): void
    {
        try {
            Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => [
                'parameters' => [['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string', $keyword => '#x']]],
                'responses' => ['200' => []],
            ]]]])->validateRequest(new Request('GET', '/h?q=x'));
            Assert::true(actual: false, message: 'Expected an unsupported keyword');
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), sprintf('Unsupported schema keyword "%s": reference identity is outside the support matrix', $keyword));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function referenceIdentityKeywords(): iterable
    {
        yield '$id' => ['$id'];
        yield '$anchor' => ['$anchor'];
        yield '$dynamicRef' => ['$dynamicRef'];
        yield '$dynamicAnchor' => ['$dynamicAnchor'];
    }

    /**
     * Every other malformedness in the same loop throws; skipping this one let
     * a parameter the document meant to declare go unvalidated.
     */
    public function rejectsANonObjectParametersEntry(): void
    {
        try {
            Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => [
                'parameters' => ['oops'],
                'responses' => ['200' => []],
            ]]]]);
            Assert::true(actual: false, message: 'Expected an invalid parameter');
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI parameter must be an object');
        }
    }

    public function pathLevelParametersApplyToEveryOperation(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => [
            'parameters' => [
                ['name' => 'a', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
                ['name' => 'b', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
            ],
            'get' => ['responses' => ['200' => []]],
        ]]]);

        $result = $contract->validateRequest(new Request('GET', '/h'));

        Assert::same(
            array_map(static fn(Violation $violation): string => $violation->instancePath, $result->violations),
            ['a', 'b'],
        );
    }

    public function reportsExactParameterCompilationMessages(): void
    {
        $document = static fn(array $parameter): array => ['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => ['parameters' => [$parameter], 'responses' => ['200' => []]]]]];

        try {
            Contract::fromArray($document(['name' => 'q', 'in' => 'body', 'schema' => ['type' => 'string']]));
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI parameter must have a valid name and location');
        }

        try {
            Contract::fromArray($document(['name' => 'q', 'in' => 'query', 'schema' => ['string']]));
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI parameter schema must be an object');
        }

        try {
            Contract::fromArray($document(['name' => 'q', 'in' => 'query', 'schema' => [0 => ['type' => 'string'], 'x' => 1]]));
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI schema keys must be strings');
        }
    }

    public function rejectsPathParametersOutsideTheTemplate(): void
    {
        try {
            Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/api/league/standings' => ['get' => [
                'parameters' => [[
                    'name' => 'division',
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ]],
                'responses' => ['200' => []],
            ]]]]);
            Assert::true(actual: false, message: 'Expected invalid path parameter exception');
        } catch (InvalidContract $exception) {
            Assert::same(
                $exception->getMessage(),
                'Path parameter "division" is not present in template "/api/league/standings"',
            );
        }
    }

    public function requiresPathParametersToExplicitlyBeRequired(): void
    {
        foreach ([[], ['required' => false]] as $required) {
            try {
                Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/pets/{id}' => ['get' => [
                    'parameters' => [
                        ['name' => 'expand', 'in' => 'query', 'schema' => ['type' => 'string']],
                        [
                            'name' => 'id',
                            'in' => 'path',
                            ...$required,
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'responses' => ['200' => []],
                ]]]]);
                Assert::true(actual: false, message: 'Expected required path parameter exception');
            } catch (InvalidContract $exception) {
                Assert::same(
                    $exception->getMessage(),
                    'Path parameter "id" in template "/pets/{id}" must declare required: true',
                );
            }
        }
    }

    public function requiresEveryTemplatePlaceholderToHaveAPathParameter(): void
    {
        try {
            Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/pets/{id}/{version}' => ['get' => [
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true]],
                'responses' => ['200' => []],
            ]]]]);
            Assert::true(actual: false, message: 'Expected missing path parameter exception');
        } catch (InvalidContract $exception) {
            Assert::same(
                $exception->getMessage(),
                'Path template "/pets/{id}/{version}" has no path parameter named "version"',
            );
        }
    }

    public function validatesTheEffectiveReferencedPathParameter(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'components' => ['parameters' => ['PetId' => [
                'name' => 'id',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'integer'],
            ]]],
            'paths' => ['/pets/{id}' => [
                'parameters' => [['$ref' => '#/components/parameters/PetId']],
                'get' => ['responses' => ['200' => []]],
            ]],
        ]);

        Assert::same($contract->operations()[0]->parameters[0]['name'], 'id');
        Assert::true($contract->operations()[0]->parameters[0]['required']);
    }

    public function compilesCookieFormParameters(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => [
            'parameters' => [['name' => 'session', 'in' => 'cookie', 'schema' => ['type' => 'string']]],
            'responses' => ['200' => []],
        ]]]]);

        Assert::same($contract->operations()[0]->parameters[0]['style'], 'form');
    }

    public function acceptsEmptyComponentsAndEmptyRequirementObjects(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'components' => [], 'paths' => ['/h' => ['get' => ['responses' => ['200' => []]]]]]);
        Assert::same(count($contract->operations()), 1);
        Assert::same($contract->securitySchemes(), []);

        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'components' => ['securitySchemes' => ['api' => ['type' => 'mutualTLS']]],
            'security' => [[]],
            'paths' => ['/h' => ['get' => ['responses' => ['200' => []]]]],
        ]);
        Assert::same($contract->operations()[0]->security, [[]]);
        Assert::same($contract->securitySchemes(), ['api' => ['type' => 'mutualTLS']]);
    }

    public function rejectsListShapedSecurityMetadata(): void
    {
        $document = static fn(array $overrides): array => ['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => ['responses' => ['200' => []]]]], ...$overrides];

        foreach ([
            ['components' => [['a']]],
            ['components' => ['securitySchemes' => ['api' => 'invalid']]],
            ['components' => ['securitySchemes' => ['' => ['type' => 'apiKey']]]],
            ['components' => ['securitySchemes' => ['api' => ['x']]]],
            ['components' => ['securitySchemes' => ['api' => ['type' => 'mutualTLS']]], 'security' => [['api' => [1]]]],
        ] as $overrides) {
            try {
                Contract::fromArray($document($overrides));
                Assert::true(actual: false);
            } catch (InvalidContract) {
                Assert::true(actual: true);
            }
        }

        try {
            Contract::fromArray($document(['components' => ['securitySchemes' => ['api']]]));
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI securitySchemes must be an object');
        }

        try {
            Contract::fromArray($document(['components' => ['securitySchemes' => ['api' => ['type' => 'mutualTLS']]], 'security' => [['x']]]));
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI security requirement must be an object');
        }
    }

    public function resolvesRequirementsAgainstEverySchemeAndKeepsAlternatives(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'components' => ['securitySchemes' => ['first' => ['type' => 'mutualTLS'], 'second' => ['type' => 'http', 'scheme' => 'basic']]],
            'security' => [['second' => ['read']], ['first' => []]],
            'paths' => ['/h' => ['get' => ['responses' => ['200' => []]]]],
        ]);

        Assert::same($contract->operations()[0]->security, [['second' => ['read']], ['first' => []]]);
    }

    public function rootAndPartialTemplatesDoNotLeakAcrossPaths(): void
    {
        $only = static function (string $path): Contract {
            preg_match_all('/\{([^{}]+)\}/', $path, $matches);
            $parameters = array_map(
                static fn(string $name): array => ['name' => $name, 'in' => 'path', 'required' => true],
                $matches[1] ?? [],
            );

            return Contract::fromArray(['openapi' => '3.1.0', 'paths' => [$path => [
                'parameters' => $parameters,
                'get' => ['responses' => ['200' => []]],
            ]]]);
        };

        Assert::null($only('/x')->match(new Request('GET', '/')));
        Assert::null($only('/')->match(new Request('GET', '/x')));
        Assert::null($only('/f/{id}.json')->match(new Request('GET', '/f/abc')));
        Assert::null($only('/{a}/x')->match(new Request('GET', '/1/y')));
    }

    public function prefersConcreteRoutesAndLongerTemplates(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => [
            '/pets/mine' => ['get' => ['operationId' => 'concrete', 'responses' => ['200' => []]]],
            '/pets/{identifier}' => [
                'parameters' => [['name' => 'identifier', 'in' => 'path', 'required' => true]],
                'get' => ['operationId' => 'templated', 'responses' => ['200' => []]],
            ],
        ]]);
        $matched = $contract->match(new Request('GET', '/pets/mine'));
        Assert::true($matched instanceof MatchedOperation);
        Assert::same($matched->operation->key, 'concrete');

        $short = [
            'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true]],
            'get' => ['operationId' => 'short', 'responses' => ['200' => []]],
        ];
        $long = [
            'parameters' => [['name' => 'store', 'in' => 'path', 'required' => true]],
            'get' => ['operationId' => 'long', 'responses' => ['200' => []]],
        ];
        foreach ([
            ['/pets/{id}' => $short, '/{store}/mine' => $long],
            ['/{store}/mine' => $long, '/pets/{id}' => $short],
        ] as $paths) {
            $matched = Contract::fromArray(['openapi' => '3.1.0', 'paths' => $paths])->match(new Request('GET', '/pets/mine'));
            Assert::true($matched instanceof MatchedOperation);
            Assert::same($matched->operation->key, 'long');
        }
    }

    public function rejectsAScalarYamlDocument(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'openapi-');
        if ($base === false) {
            throw new \RuntimeException('Unable to allocate temporary file');
        }
        $path = $base . '.yaml';
        file_put_contents($path, '42');

        try {
            Expect::exception(InvalidContract::class);
            Contract::fromFile($path);
        } finally {
            unlink($path);
            unlink($base);
        }
    }

    public function breaksBaseIndexTiesByOperationKey(): void
    {
        $first = ['get' => ['operationId' => 'aaa', 'servers' => [['url' => 'https://h/q'], ['url' => 'https://h/a']], 'responses' => ['200' => []]]];
        $second = ['get' => ['operationId' => 'zzz', 'servers' => [['url' => 'https://h/q2'], ['url' => 'https://h']], 'responses' => ['200' => []]]];
        foreach ([
            ['/x' => $first, '/a/x' => $second],
            ['/a/x' => $second, '/x' => $first],
        ] as $paths) {
            $matched = Contract::fromArray(['openapi' => '3.1.0', 'paths' => $paths])->match(new Request('GET', 'https://h/a/x'));
            Assert::true($matched instanceof MatchedOperation);
            Assert::same($matched->operation->key, 'aaa');
        }
    }

    public function rejectsARequestPathLongerThanTheRoute(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/a' => ['get' => ['responses' => ['200' => []]]]]]);

        Assert::null($contract->match(new Request('GET', '/a/b')));
    }

    public function lowerServerBaseIndexWinsTies(): void
    {
        $direct = ['get' => ['operationId' => 'direct', 'servers' => [['url' => 'https://h/a']], 'responses' => ['200' => []]]];
        $fallback = ['get' => ['operationId' => 'fallback', 'servers' => [['url' => 'https://h/q'], ['url' => 'https://h']], 'responses' => ['200' => []]]];
        foreach ([
            ['/x' => $direct, '/a/x' => $fallback],
            ['/a/x' => $fallback, '/x' => $direct],
        ] as $paths) {
            $matched = Contract::fromArray(['openapi' => '3.1.0', 'paths' => $paths])->match(new Request('GET', 'https://h/a/x'));
            Assert::true($matched instanceof MatchedOperation);
            Assert::same($matched->operation->key, 'direct');
        }
    }
}
