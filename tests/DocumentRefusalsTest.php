<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Internal\Compilation\DocumentCompiler;
use Rasuvaeff\OpenApiContract\Internal\Compilation\OperationSchemas;
use Rasuvaeff\OpenApiContract\Internal\Compilation\SecuritySchemeCompiler;
use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedDialect;
use Rasuvaeff\OpenApiContract\Internal\Response\ResponseSelector;
use Rasuvaeff\OpenApiContract\InvalidContract;
use Rasuvaeff\OpenApiContract\Tests\Support\Refusal;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Contract::class)]
#[Covers(DocumentCompiler::class)]
#[Covers(OperationSchemas::class)]
#[Covers(SecuritySchemeCompiler::class)]
#[Covers(ResponseSelector::class)]
final class DocumentRefusalsTest
{
    /**
     * @param array<array-key, mixed> $document
     * @param class-string<\Throwable> $class
     */
    #[DataProvider('refusedDocumentProvider')]
    public function refusesAMalformedDocumentWithTheReasonItGives(array $document, string $class, string $message): void
    {
        Assert::same(Refusal::of(static fn() => Contract::fromArray($document)), [$class, $message]);
    }

    /** @return iterable<string, array{array<array-key, mixed>, class-string<\Throwable>, string}> */
    public static function refusedDocumentProvider(): iterable
    {
        $operation = ['get' => ['responses' => ['200' => ['description' => 'ok']]]];
        $document = static fn(array $override): array => array_replace(['openapi' => '3.1.0', 'paths' => ['/p' => $operation]], $override);

        yield 'a path list instead of a path map' => [$document(['paths' => [$operation]]), InvalidContract::class, 'OpenAPI paths keys must be strings'];
        yield 'a path that does not start with a slash' => [$document(['paths' => ['p' => $operation]]), InvalidContract::class, 'OpenAPI path "p" must start with /'];
        yield 'a path item that is not an object' => [$document(['paths' => ['/p' => 'x']]), InvalidContract::class, 'OpenAPI path item "/p" must be an object'];
        yield 'a dialect that is not a string' => [$document(['jsonSchemaDialect' => 5]), UnsupportedDialect::class, 'Unsupported JSON Schema dialect "non-string"'];
        yield 'a dialect that is not supported' => [$document(['jsonSchemaDialect' => 'urn:other']), UnsupportedDialect::class, 'Unsupported JSON Schema dialect "urn:other"'];
        yield 'a server that is not an object' => [$document(['servers' => ['x']]), InvalidContract::class, 'OpenAPI server must contain a URL'];
        yield 'a server without a url' => [$document(['servers' => [['description' => 'x']]]), InvalidContract::class, 'OpenAPI server must contain a URL'];
        yield 'a server url that is empty' => [$document(['servers' => [['url' => '']]]), InvalidContract::class, 'OpenAPI server must contain a URL'];
        yield 'a parameter schema that is a string' => [
            $document(['paths' => ['/p' => ['get' => ['parameters' => [['name' => 'q', 'in' => 'query', 'schema' => 'x']], 'responses' => ['200' => ['description' => 'ok']]]]]]),
            InvalidContract::class,
            'OpenAPI parameter schema must be an object',
        ];
        yield 'a parameter schema that is a list' => [
            $document(['paths' => ['/p' => ['get' => ['parameters' => [['name' => 'q', 'in' => 'query', 'schema' => [1]]], 'responses' => ['200' => ['description' => 'ok']]]]]]),
            InvalidContract::class,
            'OpenAPI parameter schema must be an object',
        ];
        yield 'an encoding keyed by position' => [
            $document(['paths' => ['/p' => ['post' => ['requestBody' => ['content' => ['multipart/form-data' => ['schema' => ['type' => 'object'], 'encoding' => [5 => ['contentType' => 'text/plain']]]]], 'responses' => ['200' => ['description' => 'ok']]]]]]),
            InvalidContract::class,
            'OpenAPI encoding keys of media type "multipart/form-data" of requestBody of operation POST /p must be property name strings',
        ];
        yield 'a part header keyed by position' => [
            $document(['paths' => ['/p' => ['post' => ['requestBody' => ['content' => ['multipart/form-data' => ['schema' => ['type' => 'object'], 'encoding' => ['file' => ['headers' => [5 => ['schema' => ['type' => 'string']]]]]]]], 'responses' => ['200' => ['description' => 'ok']]]]]]),
            InvalidContract::class,
            'OpenAPI header names of encoding "file" of media type "multipart/form-data" of requestBody of operation POST /p must be strings',
        ];
        yield 'a security requirement that is a string' => [$document(['components' => ['securitySchemes' => ['k' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X']]], 'security' => ['k']]), InvalidContract::class, 'OpenAPI security requirement must be an object'];
        yield 'a security requirement that is a list' => [$document(['components' => ['securitySchemes' => ['k' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X']]], 'security' => [['k']]]), InvalidContract::class, 'OpenAPI security requirement must be an object'];
        yield 'a security requirement naming a scheme nobody declared' => [$document(['security' => [['k' => []]]]), InvalidContract::class, 'OpenAPI security requirement references unknown scheme "k"'];
        yield 'a security requirement keyed by position' => [$document(['security' => [[5 => []]]]), InvalidContract::class, 'OpenAPI security requirement references unknown scheme "5"'];
        yield 'security that is not a list' => [$document(['security' => ['k' => []]]), InvalidContract::class, 'OpenAPI security must be a list of requirement objects'];
    }

    public function skipsExtensionPathsWithoutLosingTheOnesAfterThem(): void
    {
        $operation = ['get' => ['operationId' => 'listP', 'responses' => ['200' => ['description' => 'ok']]]];
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['x-internal' => ['anything' => 1], '/p' => $operation]]);

        Assert::same($contract->operation('listP')->path, '/p');
    }

    public function compilesAGetOperationOfAWebhook(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'webhooks' => ['ping' => ['get' => ['operationId' => 'pingGet', 'responses' => ['200' => ['description' => 'ok']]]]],
        ]);

        Assert::same($contract->operation('pingGet')->method, 'GET');
        Assert::true($contract->validateWebhook('ping', new ServerRequest('GET', '/anywhere'))->isValid());
    }

    public function validatesAWebhookRequestAndItsResponseTogether(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'webhooks' => ['ping' => ['post' => [
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['id']]]]],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['ok']]]]]],
            ]]],
        ]);
        $request = static fn(string $body): ServerRequest => new ServerRequest('POST', '/hook', ['Content-Type' => 'application/json'], $body);
        $response = static fn(string $body): Response => new Response(200, ['Content-Type' => 'application/json'], $body);

        $both = $contract->validateWebhook('ping', $request('{}'), $response('{}'));
        $codes = array_map(static fn($violation): string => $violation->code, $both->violations);

        Assert::same(count($codes), 2);
        Assert::same(substr($codes[0], 0, 8), 'request.');
        Assert::same(substr($codes[1], 0, 9), 'response.');
        Assert::true($contract->validateWebhook('ping', $request('{"id":1}'), $response('{"ok":1}'))->isValid());
    }

    public function validatesTheFirstOperationOfAWebhookThatMatchesTheMethod(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'webhooks' => ['ping' => [
                'get' => ['operationId' => 'a', 'responses' => ['200' => ['description' => 'ok']]],
                'post' => ['operationId' => 'b', 'responses' => ['200' => ['description' => 'ok']]],
            ]],
        ]);

        $unknown = $contract->validateWebhook('ping', new ServerRequest('PUT', '/x'));

        Assert::false($unknown->isValid());
        Assert::same($unknown->violations[0]->message, 'Webhook "ping" declares no operation for method PUT');
        Assert::true($contract->validateWebhook('ping', new ServerRequest('post', '/x'))->isValid());
    }

    public function mergesTheOperationsOfEveryWebhookIntoOneContract(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/p' => ['get' => ['operationId' => 'p', 'responses' => ['200' => ['description' => 'ok']]]]],
            'webhooks' => [
                'one' => ['post' => ['operationId' => 'one', 'responses' => ['200' => ['description' => 'ok']]]],
                'two' => ['post' => ['operationId' => 'two', 'responses' => ['200' => ['description' => 'ok']]]],
            ],
        ]);

        Assert::same(array_map(static fn($operation): string => $operation->key, [$contract->operation('p'), $contract->operation('one'), $contract->operation('two')]), ['p', 'one', 'two']);
    }

    public function answersAnExchangeNoOperationMatches(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/p' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]]]);

        $result = $contract->validateExchange(new ServerRequest('GET', '/nowhere'), new Response(200));

        Assert::false($result->isValid());
        Assert::same($result->violations[0]->code, 'request.operation.unknown');
        Assert::same(count($result->violations), 1);
    }
}
