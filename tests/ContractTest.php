<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\ContractException;
use Rasuvaeff\OpenApiContract\ContractViolation;
use Rasuvaeff\OpenApiContract\Internal\Compilation\CompiledDocument;
use Rasuvaeff\OpenApiContract\Internal\Compilation\DocumentCompiler;
use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedDialect;
use Rasuvaeff\OpenApiContract\InvalidContract;
use Rasuvaeff\OpenApiContract\MatchedOperation;
use Rasuvaeff\OpenApiContract\UnknownOperation;
use Rasuvaeff\OpenApiContract\UnsupportedSerialization;
use Rasuvaeff\OpenApiContract\UnsupportedVersion;
use Rasuvaeff\OpenApiContract\ValidationResultFormatter;
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
#[Covers(ContractException::class)]
#[Covers(UnsupportedDialect::class)]
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

    public function reportsAYamlParseFailureAsAContractError(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'openapi-');
        if ($base === false) {
            throw new \RuntimeException('Unable to allocate temporary file');
        }
        $path = $base . '.yaml';
        file_put_contents($path, "openapi: '3.1.0'\npaths: [unclosed\n");

        try {
            Contract::fromFile($path);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            // The parser is symfony/yaml, an optional dependency; its
            // `ParseException` used to leave `fromFile()` as itself.
            Assert::same($exception->getMessage(), sprintf('OpenAPI YAML document "%s" is not valid YAML', basename($path)));
            Assert::true($exception->getPrevious() instanceof \Throwable);
        } finally {
            unlink($path);
            unlink($base);
        }
    }

    public function rejectsAMalformedRequestBodyDeclaration(): void
    {
        try {
            Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/b' => ['post' => [
                'requestBody' => 'invalid',
                'responses' => ['204' => []],
            ]]]]);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            // The response side has always rejected the same shape. A body
            // declaration read as "there is no body" made the two directions
            // disagree about one document.
            Assert::same($exception->getMessage(), 'OpenAPI requestBody of operation POST /b must be an object');
        }

        try {
            Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/b' => ['post' => [
                'requestBody' => ['content' => 'invalid'],
                'responses' => ['204' => []],
            ]]]]);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI content of requestBody of operation POST /b must be an object');
        }
    }

    public function rejectsParametersThatAreNotALIstOfDeclarations(): void
    {
        try {
            Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/p' => [
                'parameters' => 'invalid',
                'get' => ['responses' => ['204' => []]],
            ]]]);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI parameters of path item "/p" must be a list');
        }

        try {
            Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/p' => ['get' => [
                'parameters' => ['id' => ['name' => 'id', 'in' => 'query']],
                'responses' => ['204' => []],
            ]]]]);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI parameters of operation GET /p must be a list');
        }
    }

    #[DataProvider('nonBooleanFlagProvider')]
    public function rejectsANonBooleanFlag(array $document, string $message): void
    {
        try {
            Contract::fromArray($document);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), $message);
        }
    }

    public static function nonBooleanFlagProvider(): iterable
    {
        $operation = static fn(array $fields): array => ['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => [
            'responses' => ['200' => []],
            ...$fields,
        ]]]];

        yield 'parameter required' => [
            $operation(['parameters' => [['name' => 'id', 'in' => 'query', 'required' => 'true']]]),
            'OpenAPI required of parameter "id" must be a boolean',
        ];
        yield 'parameter explode' => [
            $operation(['parameters' => [['name' => 'id', 'in' => 'query', 'explode' => 'yes']]]),
            'OpenAPI explode of parameter "id" must be a boolean',
        ];
        yield 'parameter allowReserved' => [
            $operation(['parameters' => [['name' => 'id', 'in' => 'query', 'allowReserved' => 1]]]),
            'OpenAPI allowReserved of parameter "id" must be a boolean',
        ];
        yield 'response header required' => [
            ['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => ['responses' => [
                '200' => ['headers' => ['X-R' => ['required' => 'true']]],
            ]]]]],
            'OpenAPI required of header "X-R" of response "200" of operation GET /h must be a boolean',
        ];
        yield 'request body required' => [
            ['openapi' => '3.1.0', 'paths' => ['/b' => ['post' => [
                'requestBody' => ['required' => 'true', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
                'responses' => ['204' => []],
            ]]]],
            'OpenAPI required of requestBody of operation POST /b must be a boolean',
        ];
    }

    public function rejectsSchemasTheBackendCouldNeverBeHanded(): void
    {
        // YAML spells both, and every route to the backend goes through
        // `json_encode`, which cannot express either. Discovering that on the
        // first request that used the schema made a document defect a raw
        // `JsonException` out of a validate call.
        try {
            Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => [
                'parameters' => [['name' => 'n', 'in' => 'query', 'schema' => ['type' => 'number', 'multipleOf' => NAN]]],
                'responses' => ['200' => []],
            ]]]]);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::string($exception->getMessage())->contains('OpenAPI parameter schema cannot be encoded for validation');
        }

        try {
            Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/b' => ['post' => [
                'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'number', 'maximum' => INF]]]],
                'responses' => ['204' => []],
            ]]]]);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::string($exception->getMessage())->contains('OpenAPI schema of media type "application/json" of requestBody of operation POST /b cannot be encoded for validation');
        }
    }

    public function rejectsAMalformedEncodingHeaderSchema(): void
    {
        try {
            Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/b' => ['post' => [
                'requestBody' => ['content' => ['multipart/form-data' => [
                    'schema' => ['type' => 'object', 'properties' => ['file' => ['type' => 'string']]],
                    'encoding' => ['file' => ['headers' => ['X-Trace' => ['schema' => [['type' => 'string']]]]]],
                ]]],
                'responses' => ['204' => []],
            ]]]]);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same(
                $exception->getMessage(),
                'OpenAPI schema of header "X-Trace" of encoding "file" of media type "multipart/form-data" of requestBody of operation POST /b must be an object',
            );
        }
    }

    /**
     * The same document, read under the two dialects, has to mean two
     * different things: 3.0 ignores what sits next to a `$ref`, 3.1 applies
     * it in addition to what the reference brings.
     */
    #[DataProvider('refSiblingProvider')]
    public function readsRefSiblingsByDialect(string $version, string $query, bool $valid): void
    {
        $contract = Contract::fromArray([
            'openapi' => $version,
            'paths' => ['/n' => ['get' => [
                'parameters' => [['name' => 'n', 'in' => 'query', 'schema' => ['$ref' => '#/components/schemas/Count', 'maximum' => 10]]],
                'responses' => ['204' => []],
            ]]],
            'components' => ['schemas' => ['Count' => ['type' => 'integer', 'minimum' => 1]]],
        ]);

        Assert::same($contract->validateRequest(new ServerRequest('GET', '/n?' . $query))->isValid(), $valid);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function refSiblingProvider(): iterable
    {
        yield '3.1 within both bounds' => ['3.1.0', 'n=5', true];
        yield '3.1 breaks the sibling maximum' => ['3.1.0', 'n=20', false];
        yield '3.1 breaks the referenced minimum' => ['3.1.0', 'n=0', false];
        // 3.0 ignores the sibling entirely, so only the referenced bound holds.
        yield '3.0 ignores the sibling maximum' => ['3.0.3', 'n=20', true];
        yield '3.0 keeps the referenced minimum' => ['3.0.3', 'n=0', false];
    }

    /**
     * A body schema is reached by descending into a `schema` key rather than
     * by being handed to the resolver as one, so the two positions have to
     * agree about what a sibling means.
     */
    public function readsRefSiblingsOfABodySchemaTheSameWay(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/b' => ['post' => [
                'requestBody' => ['required' => true, 'content' => ['application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Count', 'maximum' => 10],
                ]]],
                'responses' => ['204' => []],
            ]]],
            'components' => ['schemas' => ['Count' => ['type' => 'integer', 'minimum' => 1]]],
        ]);
        $post = static fn(string $body): Request => new Request('POST', '/b', ['Content-Type' => 'application/json'], $body);

        Assert::true($contract->validateRequest($post('5'))->isValid());
        Assert::same($contract->validateRequest($post('20'))->violations[0]->code, 'request.body.schema');
        Assert::same($contract->validateRequest($post('0'))->violations[0]->code, 'request.body.schema');
    }

    /**
     * A Parameter Object reached through a reference is not a Schema Object,
     * and reading it as one would apply its siblings instead of ignoring them
     * — here, quietly making a required parameter optional.
     */
    public function ignoresSiblingsOfAParameterReference(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/p' => ['get' => [
                'parameters' => [['$ref' => '#/components/parameters/Id', 'required' => false, 'description' => 'mine']],
                'responses' => ['204' => []],
            ]]],
            'components' => ['parameters' => ['Id' => [
                'name' => 'id',
                'in' => 'query',
                'required' => true,
                'schema' => ['type' => 'integer'],
            ]]],
        ]);

        Assert::true($contract->operations()[0]->parameters[0]['required']);
        Assert::same($contract->validateRequest(new ServerRequest('GET', '/p'))->violations[0]->code, 'request.parameter.missing');
    }

    /**
     * Every way this package can refuse an exchange answers to one type, so
     * "handle anything the contract can throw" does not have to be spelled as
     * the current list of classes and rechecked on every upgrade.
     */
    #[DataProvider('contractFailureProvider')]
    public function raisesOneTypeForEveryWayAContractCanFail(callable $failure, string $expected): void
    {
        try {
            $failure();
            Assert::true(actual: false);
        } catch (ContractException $exception) {
            Assert::instanceOf($exception, $expected);
        }
    }

    /** @return iterable<string, array{callable(): mixed, class-string}> */
    public static function contractFailureProvider(): iterable
    {
        $contract = static fn(): Contract => Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => [
            'parameters' => [['name' => 'id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']]],
            'responses' => ['200' => []],
        ]]]]);

        yield 'unsupported version' => [
            static fn(): Contract => Contract::fromArray(['openapi' => '2.0', 'paths' => ['/h' => []]]),
            UnsupportedVersion::class,
        ];
        yield 'malformed document' => [
            static fn(): Contract => Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => 'invalid']]]),
            InvalidContract::class,
        ];
        yield 'unsupported serialization' => [
            static fn(): Contract => Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => [
                'parameters' => [['name' => 'id', 'in' => 'query', 'style' => 'label']],
                'responses' => ['200' => []],
            ]]]]),
            UnsupportedSerialization::class,
        ];
        yield 'unmatched request' => [
            static fn(): MatchedOperation => $contract()->requireMatch(new ServerRequest('GET', '/nope')),
            UnknownOperation::class,
        ];
        yield 'failed validation' => [
            static fn(): null => $contract()->validateRequest(new ServerRequest('GET', '/h'))->assertValid(),
            ContractViolation::class,
        ];
    }

    /**
     * A subschema is checked where the document is compiled, not on the first
     * message that reaches it. Read lazily, an unreadable `items` surfaced as
     * "this response header cannot be deserialized" or "this form property
     * cannot be deserialized" — the document's defect blamed on the traffic.
     */
    #[DataProvider('malformedSubschemaProvider')]
    public function rejectsAnUnreadableSubschemaWhereItIsWritten(array $document, string $message): void
    {
        try {
            Contract::fromArray($document);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), $message);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function malformedSubschemaProvider(): iterable
    {
        $broken = [['type' => 'string']];
        $response = static fn(array $definition): array => ['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => [
            'responses' => ['200' => $definition],
        ]]]];
        $body = static fn(array $schema): array => ['openapi' => '3.1.0', 'paths' => ['/b' => ['post' => [
            'requestBody' => ['content' => ['application/json' => ['schema' => $schema]]],
            'responses' => ['204' => []],
        ]]]];

        yield 'items of a parameter schema' => [
            ['openapi' => '3.1.0', 'paths' => ['/q' => ['get' => [
                'parameters' => [['name' => 'ids', 'in' => 'query', 'schema' => ['type' => 'array', 'items' => $broken]]],
                'responses' => ['204' => []],
            ]]]],
            'OpenAPI items of parameter schema must be an object',
        ];
        yield 'items of a response header schema' => [
            $response(['headers' => ['X-Tags' => ['schema' => ['type' => 'array', 'items' => $broken]]]]),
            'OpenAPI items of header "X-Tags" of response "200" of operation GET /h must be an object',
        ];
        yield 'a property of a body schema' => [
            $body(['type' => 'object', 'properties' => ['tags' => $broken]]),
            'OpenAPI properties "tags" of media type "application/json" of requestBody of operation POST /b must be an object',
        ];
        yield 'a member of allOf' => [
            $body(['allOf' => [['type' => 'object'], 'invalid']]),
            'OpenAPI allOf[1] of media type "application/json" of requestBody of operation POST /b must be an object',
        ];
        // `oneOf` without an `allOf` before it: the walk must reach every
        // keyword of the group, not stop at the first one the schema omits.
        yield 'a member of oneOf' => [
            $body(['oneOf' => [['type' => 'object'], $broken]]),
            'OpenAPI oneOf[1] of media type "application/json" of requestBody of operation POST /b must be an object',
        ];
        yield 'allOf that is not a list' => [
            $body(['allOf' => ['a' => ['type' => 'object']]]),
            'OpenAPI allOf of media type "application/json" of requestBody of operation POST /b must be a list of schemas',
        ];
        yield 'additionalProperties of a nested schema' => [
            $body(['type' => 'object', 'properties' => ['x' => ['type' => 'object', 'additionalProperties' => $broken]]]),
            'OpenAPI additionalProperties of properties "x" of media type "application/json" of requestBody of operation POST /b must be an object',
        ];
        yield 'keys of a nested schema' => [
            $body(['type' => 'object', 'properties' => ['x' => ['type' => 'string', 0 => 'malformed']]]),
            'OpenAPI schema keys of properties "x" of media type "application/json" of requestBody of operation POST /b must be strings',
        ];
    }

    /**
     * The readable-but-unconstraining forms a subschema may take, so the walk
     * above cannot be tightened into rejecting legal documents.
     */
    public function compilesSubschemasThatAreReadable(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/b' => ['post' => [
            'requestBody' => ['content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['x' => true, 'y' => ['type' => 'string'], '2020' => ['type' => 'integer']],
                'additionalProperties' => false,
                'not' => ['type' => 'null'],
                'allOf' => [['type' => 'object']],
                '$defs' => ['Named' => ['type' => 'string']],
            ]]]],
            'responses' => ['204' => []],
        ]]]]);

        Assert::same(count($contract->operations()), 1);
    }

    /**
     * RFC 3986 makes `/pets` and `/pets/` different resources. Trimming both
     * ends of both sides let a document declaring both compile and then
     * answer both requests with the same operation, leaving the other
     * unreachable.
     */
    #[DataProvider('trailingSlashProvider')]
    public function readsATrailingSlashAsPartOfThePath(string $target, ?string $expected): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => [
            '/pets' => ['get' => ['operationId' => 'noSlash', 'responses' => ['200' => []]]],
            '/pets/' => ['get' => ['operationId' => 'withSlash', 'responses' => ['200' => []]]],
        ]]);

        Assert::same($contract->match(new ServerRequest('GET', $target))?->operation->key, $expected);
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function trailingSlashProvider(): iterable
    {
        yield 'without the slash' => ['/pets', 'noSlash'];
        yield 'with the slash' => ['/pets/', 'withSlash'];
        yield 'repeated slashes are not the same path' => ['/pets//', null];
        yield 'a doubled leading slash is not the same path' => ['//pets', null];
    }

    /**
     * The diagnostic for an unmatched request used to carry the whole URI,
     * query string included, and print it verbatim — while the redaction that
     * guards `actual` found the credential *inside* that same value and
     * redacted the line below it.
     */
    public function keepsTheQueryStringOutOfTheDiagnostic(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/pets' => ['get' => [
            'responses' => ['200' => []],
        ]]]]);

        $result = $contract->validateRequest(new ServerRequest('GET', 'https://api.test/admin/export?api_key=SECRET&token=T0K3N'));
        $rendered = (new ValidationResultFormatter())->format($result);

        Assert::same($result->violations[0]->instancePath, '/admin/export');
        Assert::false(str_contains($rendered, 'SECRET'));
        Assert::false(str_contains($rendered, 'T0K3N'));
        Assert::string($rendered)->contains('/admin/export');
    }

    public function rejectsMalformedResponseContentAndSchemaKeys(): void
    {
        try {
            Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => ['responses' => [
                '200' => ['content' => [0 => ['schema' => ['type' => 'string']], 'application/json' => []]],
            ]]]]]);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI content keys of response "200" of operation GET /h must be media type strings');
        }

        try {
            Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => ['responses' => [
                '200' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 0 => 'malformed']]]],
            ]]]]]);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI schema keys of media type "application/json" of response "200" of operation GET /h must be strings');
        }
    }

    /**
     * Strictness is about shapes this package cannot read, not about
     * declarations that read fine and constrain nothing: a Media Type Object
     * without a schema, the unconstrained boolean schema, and a Header Object
     * that only has to be present all still compile.
     */
    public function compilesReadableDeclarationsThatConstrainNothing(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => ['responses' => [
            '200' => [
                'headers' => ['X-Any' => ['required' => true]],
                'content' => ['application/json' => [], 'text/plain' => ['schema' => true]],
            ],
            '404' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
        ]]]]]);

        $operation = $contract->operations()[0];
        Assert::same(array_keys($operation->responses), [200, 404]);
        Assert::same($operation->responseFor(404)['key'], '404');
        Assert::same($operation->responseFor(200)['definition']['headers'], ['X-Any' => ['required' => true]]);
    }

    public function rejectsADocumentThatDeclaresNoOperation(): void
    {
        try {
            Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/a' => [], 'x-internal' => ['get' => ['responses' => ['200' => []]]]]]);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI document declares no operations');
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

    /**
     * OpenAPI lets a placeholder share its segment with literals, and such a
     * path used to compile and then match nothing at all: the operation was
     * unreachable, and a request that literally equalled the template was
     * blamed for a missing parameter instead.
     */
    #[DataProvider('partialTemplateProvider')]
    public function matchesPartialPathTemplates(string $template, string $requestPath, ?array $expected): void
    {
        preg_match_all('/\{([^{}]+)\}/', $template, $matches);
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => [$template => [
            'parameters' => array_map(
                static fn(string $name): array => ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                $matches[1] ?? [],
            ),
            'get' => ['responses' => ['200' => []]],
        ]]]);
        $matched = $contract->match(new Request('GET', $requestPath));

        if ($expected === null) {
            Assert::null($matched);

            return;
        }
        Assert::instanceOf($matched, MatchedOperation::class);
        Assert::same($matched->pathParameters, $expected);
    }

    /** @return iterable<string, array{string, string, null|array<string, string>}> */
    public static function partialTemplateProvider(): iterable
    {
        yield 'extension suffix' => ['/report.{format}', '/report.json', ['format' => 'json']];
        yield 'prefix in the segment' => ['/v{version}/items', '/v2/items', ['version' => '2']];
        yield 'two placeholders in one segment' => ['/{a}-{b}', '/x-y', ['a' => 'x', 'b' => 'y']];
        yield 'literal around a placeholder' => ['/f/pre{id}post', '/f/pre42post', ['id' => '42']];
        yield 'suffix does not match' => ['/report.{format}', '/report', null];
        yield 'literal does not match' => ['/v{version}/items', '/x2/items', null];
        yield 'placeholder needs at least one character' => ['/report.{format}', '/report.', null];
        yield 'an encoded slash still cannot leave the segment' => ['/report.{format}', '/report.a%2Fb', null];
    }

    /**
     * A concrete path still wins over a template that also matches, whether
     * the placeholder spans the segment or shares it with a literal.
     */
    public function prefersAConcretePathOverAPartialTemplate(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => [
            '/report.{format}' => [
                'parameters' => [['name' => 'format', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                'get' => ['operationId' => 'report.byFormat', 'responses' => ['200' => []]],
            ],
            '/report.json' => ['get' => ['operationId' => 'report.json', 'responses' => ['200' => []]]],
        ]]);

        Assert::same($contract->requireMatch(new Request('GET', '/report.json'))->operation->key, 'report.json');
        Assert::same($contract->requireMatch(new Request('GET', '/report.csv'))->operation->key, 'report.byFormat');
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
