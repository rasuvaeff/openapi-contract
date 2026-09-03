<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Internal\Validation\ResponseValidator;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Contract::class)]
#[Covers(ResponseValidator::class)]
final class ResponseValidationTest
{
    public function validatesSelectedResponseAndPreservesBodyPosition(): void
    {
        $response = new Response(200, ['Content-Type' => 'application/json', 'X-Request-Id' => 'abc'], '{"id":7}');
        $response->getBody()->seek(3);

        $result = $this->contract()->validateExchange(new ServerRequest('GET', '/pets/7'), $response);

        Assert::true($result->isValid());
        Assert::same($response->getBody()->tell(), 3);
    }

    public function validatesResponseByOperationWithoutARequest(): void
    {
        $response = new Response(200, ['Content-Type' => 'application/json', 'X-Request-Id' => 'abc'], '{"id":7}');

        Assert::true($this->contract()->validateResponse('GET /pets/{id}', $response)->isValid());
        Assert::true($this->contract()->validateResponse('missing', $response)->violations[0]->code === 'response.operation.unknown');
    }

    public function refusesNonSeekableResponseBodiesWithoutReadingThem(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new \RuntimeException('Unable to create a socket pair');
        }
        fwrite($pair[0], '{}');
        fclose($pair[0]);
        $stream = Stream::create($pair[1]);
        $response = (new Response(200, ['Content-Type' => 'application/json']))->withBody($stream);

        $result = $this->contentContract(['application/json' => ['schema' => ['type' => 'object']]])
            ->validateExchange(new ServerRequest('GET', '/h'), $response);

        Assert::same($result->violations[0]->code, 'response.body.non_seekable');
        Assert::same($result->violations[0]->specPointer, '/paths/~1h/get/responses/200/content');
        Assert::same(count($result->violations), 1);
        Assert::same($stream->getContents(), '{}');
    }

    public function enforcesTheResponseBodyByteBudgetAndRestoresPosition(): void
    {
        $body = str_repeat(' ', Contract::MAX_MESSAGE_BODY_BYTES + 1);
        $response = new Response(200, ['Content-Type' => 'application/json'], $body);
        $response->getBody()->seek(11);

        $result = $this->contentContract(['application/json' => ['schema' => ['type' => 'object']]])
            ->validateExchange(new ServerRequest('GET', '/h'), $response);

        Assert::same($result->violations[0]->code, 'response.body.too_large');
        Assert::same($result->violations[0]->specPointer, '/paths/~1h/get/responses/200/content');
        Assert::same(count($result->violations), 1);
        Assert::same($response->getBody()->tell(), 11);
    }

    public function reportsStatusHeaderMediaAndSchemaViolations(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/plain'], '{"id":"bad"}');

        $result = $this->contract()->validateExchange(new ServerRequest('GET', '/pets/7'), $response);

        Assert::same(
            array_map(static fn($violation): string => $violation->code, $result->violations),
            ['response.header.missing', 'response.body.media_type'],
        );
    }

    public function acceptsRangeAndDefaultResponses(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/health' => ['get' => ['responses' => ['2XX' => [], 'default' => []]]]],
        ]);

        Assert::true($contract->validateExchange(new ServerRequest('GET', '/health'), new Response(204))->isValid());
        Assert::true($contract->validateExchange(new ServerRequest('GET', '/health'), new Response(418))->isValid());
    }

    public function matchesWildcardResponseMediaType(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/wild' => ['get' => ['responses' => [
                '200' => ['content' => ['application/*' => ['schema' => ['type' => 'object']]]],
            ]]]],
        ]);

        $response = new Response(200, ['Content-Type' => 'application/json'], '{}');
        Assert::true($contract->validateExchange(new ServerRequest('GET', '/wild'), $response)->isValid());
    }

    public function reportsUndeclaredStatusAndInvalidJson(): void
    {
        $contract = $this->contract();
        Assert::same($contract->validateExchange(new ServerRequest('GET', '/pets/7'), new Response(404))->violations[0]->code, 'response.status.mismatch');

        $response = new Response(200, ['Content-Type' => 'application/json', 'X-Request-Id' => 'abc'], '{broken');
        Assert::same($contract->validateExchange(new ServerRequest('GET', '/pets/7'), $response)->violations[0]->code, 'response.body.json');
    }

    public function reportsUnsupportedAndSchemaInvalidResponseBodies(): void
    {
        $contract = $this->contract();
        $plain = new Response(200, ['Content-Type' => 'text/plain', 'X-Request-Id' => 'abc'], '{}');
        Assert::same($contract->validateExchange(new ServerRequest('GET', '/pets/7'), $plain)->violations[0]->code, 'response.body.media_type');

        $invalid = new Response(200, ['Content-Type' => 'application/json', 'X-Request-Id' => 'abc'], '{"id":"bad"}');
        Assert::same($contract->validateExchange(new ServerRequest('GET', '/pets/7'), $invalid)->violations[0]->code, 'response.body.schema');
    }

    public function acceptsEmptyBodyWhenResponseContentIsDeclared(): void
    {
        $contract = $this->contract();
        $response = new Response(200, ['X-Request-Id' => 'abc']);

        Assert::true($contract->validateExchange(new ServerRequest('GET', '/pets/7'), $response)->isValid());
    }

    public function reportsExactResponseViolationPointers(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/a~b' => ['get' => ['responses' => [
            '200' => [
                'headers' => ['X-A~B' => ['required' => true]],
                'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]]],
            ],
        ]]]]]);
        $request = new ServerRequest('GET', '/a~b');

        $status = $contract->validateExchange($request, new Response(404));
        Assert::same($status->violations[0]->specPointer, '/paths/~1a~0b/get/responses');
        Assert::same($status->violations[0]->expected, [200]);
        Assert::same($status->violations[0]->message, 'Response status 404 is not declared');

        $header = $contract->validateExchange($request, new Response(200));
        Assert::same($header->violations[0]->specPointer, '/paths/~1a~0b/get/responses/200/headers/X-A~0B');

        $media = $contract->validateExchange($request, new Response(200, ['X-A~B' => 'v', 'Content-Type' => 'text/plain'], 'x'));
        Assert::same($media->violations[0]->specPointer, '/paths/~1a~0b/get/responses/200/content');
        Assert::same($media->violations[0]->expected, ['application/json']);
        Assert::same($media->violations[0]->message, 'Response media type "text/plain" is not declared');

        $json = $contract->validateExchange($request, new Response(200, ['X-A~B' => 'v', 'Content-Type' => 'application/json'], '{broken'));
        Assert::same($json->violations[0]->specPointer, '/paths/~1a~0b/get/responses/200/content/application~1json');
        Assert::same(count($json->violations), 1);

        $schema = $contract->validateExchange($request, new Response(200, ['X-A~B' => 'v', 'Content-Type' => 'application/json'], '{"id":"bad"}'));
        Assert::same($schema->violations[0]->specPointer, '/paths/~1a~0b/get/responses/200/content/application~1json/schema');
    }

    public function skipsMalformedAndOptionalHeaderDeclarations(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => ['responses' => [
            '204' => ['headers' => [0 => ['required' => true], 'X-Bad' => 'nonarray', 'X-Opt' => []]],
        ]]]]]);

        Assert::true($contract->validateExchange(new ServerRequest('GET', '/h'), new Response(204))->isValid());
    }

    public function emptyContentMapsSkipBodyValidation(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => ['responses' => [
            '200' => ['content' => []],
        ]]]]]);

        Assert::true($contract->validateExchange(new ServerRequest('GET', '/h'), new Response(200, [], 'plain'))->isValid());
    }

    public function normalizesTheResponseMediaType(): void
    {
        $contract = $this->contentContract(['application/json' => ['schema' => ['type' => 'object']]]);
        $response = new Response(200, ['Content-Type' => 'Application/JSON ; charset=utf-8'], '{}');

        Assert::true($contract->validateExchange(new ServerRequest('GET', '/h'), $response)->isValid());
    }

    public function honorsTheResponseJsonDepthBudget(): void
    {
        $contract = $this->contentContract(['application/json' => ['schema' => ['type' => 'array']]]);
        $deep = static fn(int $count): string => str_repeat('[', $count) . '1' . str_repeat(']', $count);

        Assert::true($contract->validateExchange(new ServerRequest('GET', '/h'), new Response(200, ['Content-Type' => 'application/json'], $deep(63)))->isValid());
        Assert::same($contract->validateExchange(new ServerRequest('GET', '/h'), new Response(200, ['Content-Type' => 'application/json'], $deep(64)))->violations[0]->code, 'response.body.json');
    }

    public function toleratesMalformedMediaDefinitionsAndSchemas(): void
    {
        $entries = $this->contentContract([0 => ['schema' => ['type' => 'string']], 'application/json' => []]);
        Assert::true($entries->validateExchange(new ServerRequest('GET', '/h'), new Response(200, ['Content-Type' => 'application/json'], '{}'))->isValid());

        $scalarSchema = $this->contentContract(['application/json' => ['schema' => 'invalid']]);
        Assert::true($scalarSchema->validateExchange(new ServerRequest('GET', '/h'), new Response(200, ['Content-Type' => 'application/json'], '{}'))->isValid());
    }

    public function matchesResponseWildcardAndSuffixDeclarations(): void
    {
        $exchange = fn(Contract $contract, Response $response) => $contract->validateExchange(new ServerRequest('GET', '/h'), $response);
        $vnd = new Response(200, ['Content-Type' => 'application/vnd.pet+json'], '{}');
        $json = new Response(200, ['Content-Type' => 'application/json'], '{}');
        $object = ['schema' => ['type' => 'object']];

        Assert::true($exchange($this->contentContract(['*/*' => $object]), $json)->isValid());
        Assert::true($exchange($this->contentContract(['application/*+json' => $object]), $vnd)->isValid());
        Assert::same($exchange($this->contentContract(['application/*+json' => $object]), $json)->violations[0]->code, 'response.body.media_type');
        Assert::true($exchange($this->contentContract(['application/hal+json' => $object]), new Response(200, ['Content-Type' => 'application/hal+json'], '{}'))->isValid());
        Assert::true($exchange($this->contentContract(['Application/JSON ; charset=utf-8' => $object]), $json)->isValid());
        Assert::true($exchange($this->contentContract(['text/plain' => []]), new Response(200, ['Content-Type' => 'text/plain'], 'hello'))->isValid());
    }

    public function keepsHeaderViolationsNextToANonJsonBodyViolation(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => ['responses' => [
            '200' => [
                'headers' => ['X-Request-Id' => ['required' => true, 'schema' => ['type' => 'string']]],
                'content' => ['text/plain' => ['schema' => ['type' => 'string', 'maxLength' => 3]]],
            ],
        ]]]]]);

        $result = $contract->validateExchange(new ServerRequest('GET', '/h'), new Response(200, ['Content-Type' => 'text/plain'], 'hello'));

        Assert::same(
            array_map(static fn($violation): string => $violation->code, $result->violations),
            ['response.header.missing', 'response.body.schema'],
        );
    }

    public function validatesDeclaredNonJsonBodiesAsFarAsTheSchemaAllows(): void
    {
        $exchange = fn(Contract $contract, Response $response) => $contract->validateExchange(new ServerRequest('GET', '/h'), $response);
        $plain = fn(string $body): Response => new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], $body);

        Assert::true($exchange($this->contentContract(['text/plain' => ['schema' => []]]), $plain('hello'))->isValid());
        Assert::true($exchange($this->contentContract(['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]), new Response(200, ['Content-Type' => 'application/octet-stream'], "\x00\xff"))->isValid());
        Assert::true($exchange($this->contentContract(['text/plain' => ['schema' => ['type' => 'string', 'maxLength' => 5]]]), $plain('hello'))->isValid());

        $tooLong = $exchange($this->contentContract(['text/plain' => ['schema' => ['type' => 'string', 'maxLength' => 3]]]), $plain('hello'));
        Assert::same($tooLong->violations[0]->code, 'response.body.schema');
        Assert::same($tooLong->violations[0]->specPointer, '/paths/~1h/get/responses/200/content/text~1plain/schema');
        Assert::same($tooLong->violations[0]->actual, 'hello');
        Assert::same(count($tooLong->violations), 1);

        $unsupported = $exchange($this->contentContract(['application/xml' => ['schema' => ['type' => 'object']]]), new Response(200, ['Content-Type' => 'application/xml'], '<a/>'));
        Assert::same($unsupported->violations[0]->code, 'response.body.unsupported');
        Assert::same($unsupported->violations[0]->message, 'Response media type "application/xml" cannot be validated against a non-string schema');
        Assert::same($unsupported->violations[0]->specPointer, '/paths/~1h/get/responses/200/content/application~1xml/schema');
        Assert::same($unsupported->violations[0]->actual, 'application/xml');
        Assert::same(count($unsupported->violations), 1);
        Assert::same($exchange($this->contentContract(['text/plain' => ['schema' => ['type' => ['string', 'integer']]]]), $plain('1'))->violations[0]->code, 'response.body.unsupported');
    }

    public function checksEveryHeaderDeclarationPastMalformedEntries(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => ['responses' => [
            '204' => ['headers' => [0 => ['required' => true], 'X-Req' => ['required' => true]]],
        ]]]]]);

        $result = $contract->validateExchange(new ServerRequest('GET', '/h'), new Response(204));

        Assert::same($result->violations[0]->code, 'response.header.missing');
        Assert::same($result->violations[0]->instancePath, 'X-Req');
    }

    public function selectsTheDefinitionByExactTypeBeforeWildcards(): void
    {
        $contract = $this->contentContract(['text/*' => ['schema' => ['type' => 'string']], 'application/json' => ['schema' => ['type' => 'integer']]]);

        Assert::true($contract->validateExchange(new ServerRequest('GET', '/h'), new Response(200, ['Content-Type' => 'application/json'], '7'))->isValid());
    }

    public function validatesPresentHeaderValuesAgainstTheirSchema(): void
    {
        $contract = $this->headerContract([
            'X-RateLimit-Remaining' => ['required' => true, 'schema' => ['type' => 'integer', 'minimum' => 0]],
            'X-Mode' => ['schema' => ['type' => 'string', 'enum' => ['fast', 'slow']]],
        ]);

        Assert::true($this->validate($contract, ['X-RateLimit-Remaining' => '12', 'X-Mode' => 'fast'])->isValid());
        Assert::true($this->validate($contract, ['X-RateLimit-Remaining' => '0'])->isValid());

        $result = $this->validate($contract, ['X-RateLimit-Remaining' => 'banana', 'X-Mode' => 'medium']);
        Assert::same(
            array_map(static fn($violation): string => $violation->code, $result->violations),
            ['response.header.schema', 'response.header.schema'],
        );
        Assert::same($result->violations[0]->location, 'header');
        Assert::same($result->violations[0]->instancePath, 'X-RateLimit-Remaining');
        Assert::same($result->violations[0]->specPointer, '/paths/~1h/get/responses/200/headers/X-RateLimit-Remaining/schema');
        Assert::same($result->violations[0]->expected, ['type' => 'integer', 'minimum' => 0]);
        Assert::same($result->violations[0]->actual, 'banana');
        Assert::same($result->violations[0]->message, 'Response header "X-RateLimit-Remaining" does not match its schema');
        Assert::same($result->violations[1]->actual, 'medium');

        Assert::same($this->validate($contract, ['X-RateLimit-Remaining' => '-1'])->violations[0]->actual, -1);

        $result = $this->validate($contract, ['X-Mode' => 'medium']);
        Assert::same(
            array_map(static fn($violation): string => $violation->code, $result->violations),
            ['response.header.missing', 'response.header.schema'],
        );
    }

    public function decodesArrayAndObjectHeadersWithTheSimpleStyle(): void
    {
        $contract = $this->headerContract([
            'X-Ids' => ['schema' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 2]],
            'X-Point' => ['schema' => ['type' => 'object', 'required' => ['x', 'y'], 'properties' => ['x' => ['type' => 'integer'], 'y' => ['type' => 'integer']]]],
            'X-Exploded' => ['explode' => true, 'schema' => ['type' => 'object', 'required' => ['x'], 'properties' => ['x' => ['type' => 'integer']]]],
        ]);

        Assert::true($this->validate($contract, ['X-Ids' => '1,2', 'X-Point' => 'x,1,y,2', 'X-Exploded' => 'x=1'])->isValid());
        Assert::true($this->validate($contract, ['X-Ids' => ['3', '4'], 'X-Point' => 'x , 1 ,y, 2'])->isValid());

        $result = $this->validate($contract, ['X-Ids' => '1', 'X-Point' => 'x,1', 'X-Exploded' => 'y=1']);
        Assert::same(
            array_map(static fn($violation): string => $violation->code, $result->violations),
            ['response.header.schema', 'response.header.schema', 'response.header.schema'],
        );
        Assert::same($result->violations[0]->actual, [1]);
        Assert::same((array) $result->violations[1]->actual, ['x' => 1]);
        Assert::same((array) $result->violations[2]->actual, ['y' => '1']);
    }

    public function reportsHeadersThatCannotBeDeserialized(): void
    {
        $contract = $this->headerContract(['X-Point' => ['schema' => ['type' => 'object']]]);

        $result = $this->validate($contract, ['X-Point' => 'x,1,y']);

        Assert::same($result->violations[0]->code, 'response.header.serialization');
        Assert::same($result->violations[0]->location, 'header');
        Assert::same($result->violations[0]->instancePath, 'X-Point');
        Assert::same($result->violations[0]->specPointer, '/paths/~1h/get/responses/200/headers/X-Point');
        Assert::same($result->violations[0]->expected, 'simple');
        Assert::same($result->violations[0]->actual, 'x,1,y');
        Assert::same($result->violations[0]->message, 'Response header "X-Point" cannot be deserialized');
        Assert::same(count($result->violations), 1);
    }

    public function failsClosedOnContentFormAndNonSimpleHeaderDeclarations(): void
    {
        $contract = $this->headerContract([
            'X-Json' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
            'X-Label' => ['style' => 'label', 'schema' => ['type' => 'string']],
            'X-Simple' => ['style' => 'simple', 'schema' => ['type' => 'string']],
        ]);

        Assert::true($this->validate($contract, [])->isValid());
        Assert::true($this->validate($contract, ['X-Simple' => 'ok'])->isValid());

        $result = $this->validate($contract, ['X-Json' => '{}', 'X-Label' => '.a']);
        Assert::same(
            array_map(static fn($violation): string => $violation->code, $result->violations),
            ['response.header.unsupported', 'response.header.unsupported'],
        );
        Assert::same($result->violations[0]->specPointer, '/paths/~1h/get/responses/200/headers/X-Json');
        Assert::same($result->violations[0]->expected, 'simple-style Header Object with a schema');
        Assert::same($result->violations[0]->actual, 'content');
        Assert::same($result->violations[0]->message, 'Response header "X-Json" cannot be validated against its declaration');
        Assert::same($result->violations[1]->actual, 'label');
    }

    public function assertsOnlyPresenceForSchemaLessHeadersAndIgnoresContentType(): void
    {
        $contract = $this->headerContract([
            'X-Any' => ['required' => true, 'description' => 'no schema'],
            'Content-Type' => ['required' => true, 'schema' => ['type' => 'integer']],
            'X-Bad' => ['schema' => ['type' => 'integer', 0 => 'malformed']],
        ]);

        Assert::true($this->validate($contract, ['X-Any' => 'anything, at all', 'X-Bad' => 'not an integer'])->isValid());
        Assert::same($this->validate($contract, [])->violations[0]->code, 'response.header.missing');
        Assert::same(count($this->validate($contract, [])->violations), 1);
    }

    public function validatesHeadersUnderTheOpenApi30Dialect(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.0.3', 'paths' => ['/h' => ['get' => ['responses' => [
            '200' => ['description' => 'ok', 'headers' => ['X-Count' => ['schema' => ['type' => 'integer', 'nullable' => true]]]],
        ]]]]]);

        Assert::true($contract->validateResponse('GET /h', new Response(200, ['X-Count' => '5']))->isValid());
        Assert::same($contract->validateResponse('GET /h', new Response(200, ['X-Count' => 'x']))->violations[0]->code, 'response.header.schema');
    }

    /** @param array<string, array<array-key, mixed>> $headers */
    private function headerContract(array $headers): Contract
    {
        return Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => ['responses' => [
            '200' => ['headers' => $headers],
        ]]]]]);
    }

    /** @param array<string, string|list<string>> $headers */
    private function validate(Contract $contract, array $headers): \Rasuvaeff\OpenApiContract\ValidationResult
    {
        return $contract->validateExchange(new ServerRequest('GET', '/h'), new Response(200, $headers));
    }

    /** @param array<array-key, mixed> $content */
    private function contentContract(array $content): Contract
    {
        return Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/h' => ['get' => ['responses' => [
            '200' => ['content' => $content],
        ]]]]]);
    }

    private function contract(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets/{id}' => ['get' => [
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                'responses' => [
                    '200' => [
                        'headers' => ['X-Request-Id' => ['required' => true]],
                        'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'required' => ['id'],
                            'properties' => ['id' => ['type' => 'integer']],
                        ]]],
                    ],
                ],
            ]]],
        ]);
    }
}
