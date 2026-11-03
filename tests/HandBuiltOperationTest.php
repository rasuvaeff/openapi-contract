<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Internal\Validation\MediaType;
use Rasuvaeff\OpenApiContract\Internal\Validation\MessageReading;
use Rasuvaeff\OpenApiContract\Internal\Validation\RequestValidator;
use Rasuvaeff\OpenApiContract\Internal\Validation\ResponseValidator;
use Rasuvaeff\OpenApiContract\InvalidContract;
use Rasuvaeff\OpenApiContract\Limits;
use Rasuvaeff\OpenApiContract\MatchedOperation;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\OpenApiContract\SchemaDialect;
use Rasuvaeff\OpenApiContract\Tests\Support\Refusal;
use Rasuvaeff\OpenApiContract\Tests\Support\ViolationRow;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The validators read an `Operation` the compiler built, and nothing public
 * checks one built by hand; these are the branches only a hand-built one
 * reaches, and the media type selection that a document cannot make tie.
 */
#[Test]
#[Covers(RequestValidator::class)]
#[Covers(ResponseValidator::class)]
#[Covers(MessageReading::class)]
#[Covers(MediaType::class)]
final class HandBuiltOperationTest
{
    public function aRequestBodyWithoutContentHasNoSupportedDefinition(): void
    {
        $operation = new Operation(key: 'k', operationId: null, method: 'POST', path: '/p', requestBody: ['required' => true]);

        $result = (new RequestValidator(new Limits()))->validate(new MatchedOperation($operation, []), new ServerRequest('POST', '/p'), SchemaDialect::OpenApi31);

        Assert::same(ViolationRow::of($result), [[
            'request.body.media_type',
            'body',
            '$',
            '/paths/~1p/post/requestBody',
            ['required' => true],
            null,
            'Request body has no supported content definition',
        ]]);
    }

    public function aParameterStyleTheCompilerNeverEmitsIsAnInternalError(): void
    {
        $operation = new Operation(key: 'k', operationId: null, method: 'GET', path: '/p', parameters: [[
            'name' => 'q',
            'in' => 'query',
            'required' => false,
            'style' => 'bogus',
            'explode' => true,
            'allowReserved' => false,
            'schema' => ['type' => 'string'],
            'specPointer' => '/paths/~1p/get/parameters/0',
        ]]);

        Assert::same(
            Refusal::of(static fn() => (new RequestValidator(new Limits()))->validate(new MatchedOperation($operation, []), new ServerRequest('GET', '/p?q=1'), SchemaDialect::OpenApi31)),
            [\LogicException::class, 'Unsupported compiled parameter style "bogus"'],
        );
    }

    public function aResponseHeaderTheDocumentCannotBeReadFromIsTheDocumentsDefect(): void
    {
        $operation = new Operation(key: 'k', operationId: null, method: 'GET', path: '/p', responses: ['200' => ['headers' => [
            'X-Obj' => ['schema' => ['type' => 'object', 'properties' => ['k' => 5]]],
        ]]]);

        Assert::same(
            Refusal::of(static fn() => (new ResponseValidator(new Limits()))->validate(new MatchedOperation($operation, []), new Response(200, ['X-Obj' => 'k,1']), SchemaDialect::OpenApi31)),
            [InvalidContract::class, 'Schema must be an object'],
        );
    }

    public function aResponseHeaderKeyedByPositionIsSkippedAndTheNextOneIsRead(): void
    {
        $operation = new Operation(key: 'k', operationId: null, method: 'GET', path: '/p', responses: ['200' => ['headers' => [
            0 => ['required' => true],
            'X-Req' => ['required' => true],
        ]]]);

        $result = Refusal::quietly(static fn() => (new ResponseValidator(new Limits()))->validate(new MatchedOperation($operation, []), new Response(200), SchemaDialect::OpenApi31));

        Assert::same(array_map(static fn(array $row): string => $row[2], ViolationRow::of($result)), ['X-Req']);
    }

    public function aContentEntryKeyedByPositionIsNeverASelectedMediaType(): void
    {
        $operation = new Operation(key: 'k', operationId: null, method: 'GET', path: '/p', responses: ['200' => ['content' => [
            0 => ['schema' => ['type' => 'string']],
            'application/json' => ['schema' => ['type' => 'object']],
        ]]]);

        $result = Refusal::quietly(static fn() => (new ResponseValidator(new Limits()))->validate(
            new MatchedOperation($operation, []),
            new Response(200, ['Content-Type' => 'application/json'], '{}'),
            SchemaDialect::OpenApi31,
        ));

        Assert::true($result->isValid());
    }

    public function aContentEntryThatIsNotAnObjectIsNeverASelectedMediaType(): void
    {
        $operation = new Operation(key: 'k', operationId: null, method: 'GET', path: '/p', responses: ['200' => ['content' => [
            'application/json' => 'oops',
            'application/*' => ['schema' => ['type' => 'object']],
        ]]]);

        $result = (new ResponseValidator(new Limits()))->validate(
            new MatchedOperation($operation, []),
            new Response(200, ['Content-Type' => 'application/json'], '[]'),
            SchemaDialect::OpenApi31,
        );

        Assert::same(array_map(static fn(array $row): string => $row[0], ViolationRow::of($result)), ['response.body.schema']);
    }

    public function theFirstOfTwoDeclarationsOfOneMediaTypeWins(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/p' => ['get' => ['responses' => ['200' => ['content' => [
                'application/json' => ['schema' => ['type' => 'object']],
                'application/json; charset=utf-8' => ['schema' => ['type' => 'array']],
            ]]]]]],
        ]);

        $response = new Response(200, ['Content-Type' => 'application/json'], '{}');

        Assert::true($contract->validateExchange(new ServerRequest('GET', '/p'), $response)->isValid());
    }

    public function aMoreSpecificDeclarationBeatsALaterWildcard(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/p' => ['get' => ['responses' => ['200' => ['content' => [
                'application/*' => ['schema' => ['type' => 'array']],
                'application/json' => ['schema' => ['type' => 'object']],
                '*/*' => ['schema' => ['type' => 'string']],
            ]]]]]],
        ]);

        Assert::true($contract->validateExchange(new ServerRequest('GET', '/p'), new Response(200, ['Content-Type' => 'application/json'], '{}'))->isValid());
        Assert::false($contract->validateExchange(new ServerRequest('GET', '/p'), new Response(200, ['Content-Type' => 'application/xml'], '{}'))->isValid());
    }

    public function aMediaTypeWithNoSubtypeMatchesNothingButItself(): void
    {
        Assert::same(MediaType::specificity('text', 'text'), 3);
        Assert::null(MediaType::specificity('text', 'text/plain'));
        Assert::null(MediaType::specificity('text/plain', 'text'));
        Assert::null(MediaType::specificity('text/*', 'image/png'));
        Assert::same(MediaType::specificity('text/*', 'text/plain'), 1);
        Assert::same(MediaType::specificity('*/*', 'a/b'), 0);
        Assert::same(MediaType::specificity('application/*+json', 'application/ld+json'), 2);
        Assert::null(MediaType::specificity('application/*+json', 'application/xml'));
        Assert::same(MediaType::normalize('Text/Plain; charset=a;b=c'), 'text/plain');
        Assert::same(MediaType::normalize(' text/plain ;x'), 'text/plain');
        Assert::null(MediaType::specificity('a/b/c', 'a/b'));
        Assert::null(MediaType::specificity('a/b', 'a/b/c'));
    }
}
