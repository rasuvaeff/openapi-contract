<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\ContractViolation;
use Rasuvaeff\OpenApiContract\Internal\Validation\RequestValidator;
use Rasuvaeff\OpenApiContract\MatchedOperation;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\OpenApiContract\ValidationResult;
use Rasuvaeff\OpenApiContract\Violation;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(Contract::class)]
#[Covers(RequestValidator::class)]
#[Covers(Operation::class)]
#[Covers(MatchedOperation::class)]
#[Covers(ValidationResult::class)]
#[Covers(Violation::class)]
#[Covers(ContractViolation::class)]
final class RequestValidationTest
{
    public function validatesParametersAndJsonBody(): void
    {
        $request = new ServerRequest('POST', '/pets/42?tag=small&tag=friendly', [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Tenant' => 'public',
        ], '{"name":"Milo"}');

        $result = $this->contract()->validateRequest($request);

        Assert::true($result->isValid());
        $result->assertValid();
    }

    public function aggregatesIndependentRequestViolations(): void
    {
        $request = new ServerRequest('POST', '/pets/not-an-integer', [
            'Content-Type' => 'application/json',
        ], '{broken');

        $result = $this->contract()->validateRequest($request);

        Assert::false($result->isValid());
        Assert::same(
            array_map(static fn(Violation $violation): string => $violation->code, $result->violations),
            ['request.parameter.schema', 'request.parameter.missing', 'request.body.json'],
        );
        Expect::exception(ContractViolation::class);
        $result->assertValid();
    }

    public function preservesSeekableBodyPosition(): void
    {
        $request = new ServerRequest('POST', '/pets/42', [
            'Content-Type' => 'application/json',
            'X-Tenant' => 'public',
        ], '{"name":"Milo"}');
        $request->getBody()->seek(5);

        $this->contract()->validateRequest($request);

        Assert::same($request->getBody()->tell(), 5);
    }

    public function refusesNonSeekableRequestBodiesWithoutReadingThem(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new \RuntimeException('Unable to create a socket pair');
        }
        fwrite($pair[0], '{}');
        fclose($pair[0]);
        $stream = Stream::create($pair[1]);
        $request = (new ServerRequest('POST', '/b', ['Content-Type' => 'application/json']))->withBody($stream);

        $result = $this->bodyContract(['application/json' => ['schema' => ['type' => 'object']]])
            ->validateRequest($request);

        Assert::same($result->violations[0]->code, 'request.body.non_seekable');
        Assert::same($stream->getContents(), '{}');
    }

    public function restoresSeekableRequestBodyPositionWhenReadingThrows(): void
    {
        $stream = Understudy::for(StreamInterface::class);
        when(static fn(): bool => $stream->isSeekable())->returns(true);
        when(static fn(): int => $stream->tell())->returns(5);
        when(static fn(): bool => $stream->eof())->returns(false);
        when(static fn(): string => $stream->read(8192))->throws(new \RuntimeException('read failed'));
        expect(static fn() => $stream->seek(5));
        $request = (new ServerRequest('POST', '/b', ['Content-Type' => 'application/json']))->withBody($stream);

        try {
            $this->bodyContract(['application/json' => ['schema' => ['type' => 'object']]])
                ->validateRequest($request);
            Assert::true(actual: false, message: 'Expected body read failure');
        } catch (\RuntimeException $exception) {
            Assert::same($exception->getMessage(), 'read failed');
        }
    }

    public function enforcesTheRequestBodyByteBudgetAndRestoresPosition(): void
    {
        $body = str_repeat(' ', Contract::MAX_MESSAGE_BODY_BYTES + 1);
        $request = new ServerRequest('POST', '/b', ['Content-Type' => 'application/json'], $body);
        $request->getBody()->seek(7);

        $result = $this->bodyContract(['application/json' => ['schema' => ['type' => 'object']]])
            ->validateRequest($request);

        Assert::same($result->violations[0]->code, 'request.body.too_large');
        Assert::same($request->getBody()->tell(), 7);
    }

    public function acceptsARequestBodyAtTheExactByteBudget(): void
    {
        $body = '{"x":"' . str_repeat('a', Contract::MAX_MESSAGE_BODY_BYTES - 8) . '"}';
        Assert::same(strlen($body), Contract::MAX_MESSAGE_BODY_BYTES);
        $request = new ServerRequest('POST', '/b', ['Content-Type' => 'application/json'], $body);

        $result = $this->bodyContract(['application/json' => ['schema' => ['type' => 'object']]])
            ->validateRequest($request);

        Assert::true($result->isValid());
    }

    public function reportsUnknownOperationWithoutCascadingErrors(): void
    {
        $result = $this->contract()->validateRequest(new ServerRequest('GET', '/missing'));

        Assert::same(count($result->violations), 1);
        Assert::same($result->violations[0]->code, 'request.operation.unknown');
    }

    public function acceptsExplodedAdditionalPropertiesObject(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/search' => ['get' => [
                'parameters' => [[
                    'name' => 'filter', 'in' => 'query', 'required' => true,
                    'style' => 'form', 'explode' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => ['kind' => ['type' => 'string']],
                        'additionalProperties' => ['type' => 'string'],
                    ],
                ]],
                'responses' => ['200' => []],
            ]]],
        ]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/search?kind=animal&term=cat'))->isValid());
    }

    public function matchesWildcardRequestMediaType(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/wild' => ['post' => [
                'requestBody' => ['required' => true, 'content' => ['*/*' => ['schema' => ['type' => 'object']]]],
                'responses' => ['204' => []],
            ]]],
        ]);

        $request = new ServerRequest('POST', '/wild', ['Content-Type' => 'application/json'], '{}');
        Assert::true($contract->validateRequest($request)->isValid());
    }

    public function reportsMissingRequiredBodyAndMediaType(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/body' => ['post' => [
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
                'responses' => ['204' => []],
            ]]],
        ]);

        Assert::same($contract->validateRequest(new ServerRequest('POST', '/body'))->violations[0]->code, 'request.body.missing');
        Assert::same($contract->validateRequest(new ServerRequest('POST', '/body', ['Content-Type' => 'text/plain'], '{}'))->violations[0]->code, 'request.body.media_type');
    }

    public function reportsMalformedAndSchemaInvalidJsonBodies(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/body' => ['post' => [
                'requestBody' => ['required' => false, 'content' => ['application/problem+json' => ['schema' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]]]],
                'responses' => ['204' => []],
            ]]],
        ]);

        Assert::same($contract->validateRequest(new ServerRequest('POST', '/body', ['Content-Type' => 'application/problem+json'], '{broken'))->violations[0]->code, 'request.body.json');
        Assert::same($contract->validateRequest(new ServerRequest('POST', '/body', ['Content-Type' => 'application/problem+json'], '{"id":"wrong"}'))->violations[0]->code, 'request.body.schema');
    }

    public function reportsOptionalMissingParameterAndSerializationErrors(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/items' => ['get' => [
                'parameters' => [
                    ['name' => 'required', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']],
                    ['name' => 'tags', 'in' => 'query', 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ],
                'responses' => ['200' => []],
            ]]],
        ]);

        $missing = $contract->validateRequest(new ServerRequest('GET', '/items'));
        Assert::same(array_map(static fn(Violation $v): string => $v->code, $missing->violations), ['request.parameter.missing']);
        $invalid = $contract->validateRequest(new ServerRequest('GET', '/items?required=x&tags=a%2Fb%2Cc'));
        Assert::false($invalid->isValid());
    }

    public function readOnlyAndWriteOnlyPropertiesFollowRequestDirection(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/items' => ['post' => [
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => ['id', 'name'],
                    'properties' => [
                        'id' => ['type' => 'integer', 'readOnly' => true],
                        'name' => ['type' => 'string', 'writeOnly' => true],
                    ],
                ]]]],
                'responses' => ['204' => []],
            ]]],
        ]);

        $request = new ServerRequest('POST', '/items', ['Content-Type' => 'application/json'], '{"name":"ok"}');
        Assert::true($contract->validateRequest($request)->isValid());
    }

    public function reportsExactParameterViolationPointers(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/a~b' => ['get' => [
            'parameters' => [['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']]],
            'responses' => ['200' => []],
        ]]]]);

        $missing = $contract->validateRequest(new ServerRequest('GET', '/a~b'));
        Assert::same($missing->violations[0]->specPointer, '/paths/~1a~0b/get/parameters/0');

        $mismatch = $contract->validateRequest(new ServerRequest('GET', '/a~b?q=x'));
        Assert::same($mismatch->violations[0]->specPointer, '/paths/~1a~0b/get/parameters/0/schema');
        Assert::same($mismatch->violations[0]->message, 'Query parameter "q" does not match its schema');
    }

    public function reportsExactBodyViolationPointer(): void
    {
        $result = $this->bodyContract(['application/json' => ['schema' => ['type' => 'object']]])
            ->validateRequest(new ServerRequest('POST', '/b'));

        Assert::same($result->violations[0]->specPointer, '/paths/~1b/post/requestBody');
    }

    public function validatesCookieParameters(): void
    {
        $contract = $this->paramContract(['name' => 'sid', 'in' => 'cookie', 'required' => true, 'schema' => ['type' => 'integer']]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/q', ['Cookie' => 'sid=7']))->isValid());
        Assert::same($contract->validateRequest(new ServerRequest('GET', '/q'))->violations[0]->code, 'request.parameter.missing');
    }

    public function missingQueryStringYieldsAMissingParameter(): void
    {
        $contract = $this->paramContract([
            'name' => 'f', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true,
            'schema' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
        ]);

        Assert::same($contract->validateRequest(new ServerRequest('GET', '/q'))->violations[0]->code, 'request.parameter.missing');
    }

    public function nonExplodedObjectsIgnoreForeignQueryPairs(): void
    {
        $contract = $this->paramContract([
            'name' => 'o', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => false,
            'schema' => ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'integer']]],
        ]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/q?o=a,1&z=5'))->isValid());
    }

    public function explodedObjectsWithoutDeclaredExtrasKeepEveryPair(): void
    {
        $contract = $this->paramContract([
            'name' => 'f', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true,
            'schema' => ['type' => 'object', 'minProperties' => 2, 'properties' => ['a' => ['type' => 'string']]],
        ]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/q?a=x&b=y'))->isValid());
    }

    public function explodedObjectsWithForbiddenExtrasFilterUndeclaredPairs(): void
    {
        $contract = $this->paramContract([
            'name' => 'f', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true,
            'schema' => ['type' => 'object', 'minProperties' => 2, 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']], 'additionalProperties' => false],
        ]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/q?a=x&b=y&zzz=1'))->isValid());
    }

    public function scalarParametersIgnoreObjectQueryHandling(): void
    {
        $contract = $this->paramContract([
            'name' => 'q', 'in' => 'query', 'required' => true,
            'schema' => ['type' => 'string', 'additionalProperties' => false],
        ]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/q?q=x'))->isValid());
    }

    public function requiredDeepObjectsAcceptForeignPrefixedPairs(): void
    {
        $contract = $this->paramContract([
            'name' => 'do', 'in' => 'query', 'required' => true, 'style' => 'deepObject', 'explode' => true,
            'schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]],
        ]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/q?do%5Ba%5D=5&dob=7'))->isValid());
    }

    public function explodedCookieObjectsKeepEveryPair(): void
    {
        $contract = $this->paramContract([
            'name' => 'f', 'in' => 'cookie', 'required' => true, 'style' => 'form', 'explode' => true,
            'schema' => ['type' => 'object', 'minProperties' => 2, 'properties' => ['a' => ['type' => 'string']]],
        ]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/q', ['Cookie' => 'a=x; b=y']))->isValid());
    }

    public function explodedCookieObjectsWithForbiddenExtrasFilterUndeclaredPairs(): void
    {
        $contract = $this->paramContract([
            'name' => 'f', 'in' => 'cookie', 'required' => true, 'style' => 'form', 'explode' => true,
            'schema' => ['type' => 'object', 'minProperties' => 2, 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']], 'additionalProperties' => false],
        ]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/q', ['Cookie' => 'a=x; b=y; zzz=1']))->isValid());
    }

    public function nonExplodedCookieObjectsBypassPairFiltering(): void
    {
        $contract = $this->paramContract([
            'name' => 'o', 'in' => 'cookie', 'required' => true, 'style' => 'form', 'explode' => false,
            'schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'additionalProperties' => false],
        ]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/q', ['Cookie' => 'o=a,1']))->isValid());
    }

    public function absentCookiesYieldAMissingParameter(): void
    {
        $contract = $this->paramContract([
            'name' => 'f', 'in' => 'cookie', 'required' => true, 'style' => 'form', 'explode' => true,
            'schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
        ]);

        Assert::same($contract->validateRequest(new ServerRequest('GET', '/q'))->violations[0]->code, 'request.parameter.missing');
    }

    public function coercedBooleansKeepTheirTruthValue(): void
    {
        $contract = $this->paramContract(['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'boolean', 'const' => true]]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/q?q=true'))->isValid());
    }

    #[DataProvider('coercionCases')]
    public function coercesScalarWireValuesByType(array $schema, string $value, bool $valid): void
    {
        $contract = $this->paramContract(['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => $schema]);

        Assert::same($contract->validateRequest(new ServerRequest('GET', '/q?q=' . rawurlencode($value)))->isValid(), $valid);
    }

    /** @return iterable<string, array{array<string, mixed>, string, bool}> */
    public static function coercionCases(): iterable
    {
        yield 'nullable rejects junk' => [['type' => ['null', 'integer']], 'x', false];
        yield 'nullable accepts null literal' => [['type' => ['null', 'integer']], 'null', true];
        yield 'string keeps literal null' => [['type' => 'string'], 'null', true];
        yield 'integer rejects trailing junk' => [['type' => 'integer'], '5x', false];
        yield 'integer rejects leading junk' => [['type' => 'integer'], 'x5', false];
        yield 'string keeps numeric text' => [['type' => 'string'], '5', true];
        yield 'number rejects text' => [['type' => 'number'], 'abc', false];
        yield 'number accepts decimals' => [['type' => 'number'], '1.5', true];
        yield 'string keeps boolean text' => [['type' => 'string'], 'true', true];
        yield 'boolean accepts true' => [['type' => 'boolean'], 'true', true];
        yield 'boolean rejects junk' => [['type' => 'boolean'], 'x', false];
    }

    public function coercesArrayItemsByTheItemSchema(): void
    {
        $contract = $this->paramContract(['name' => 'ids', 'in' => 'query', 'required' => true, 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'integer']]]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/q?ids=1,2'))->isValid());
    }

    public function optionalBodyMayBeOmitted(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/b' => ['post' => [
            'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
            'responses' => ['204' => []],
        ]]]]);

        Assert::true($contract->validateRequest(new ServerRequest('POST', '/b'))->isValid());
    }

    public function normalizesTheRequestMediaTypeBeforeMatching(): void
    {
        $contract = $this->bodyContract(['application/json' => ['schema' => ['type' => 'object']]]);

        $request = new ServerRequest('POST', '/b', ['Content-Type' => 'Application/JSON ; charset=utf-8'], '{}');
        Assert::true($contract->validateRequest($request)->isValid());
    }

    public function reportsExactUndeclaredMediaTypeMessages(): void
    {
        $undeclared = $this->bodyContract(['application/json' => ['schema' => ['type' => 'object']]])
            ->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'text/plain'], 'hello'));
        Assert::same($undeclared->violations[0]->message, 'Request media type "text/plain" is not declared');

        $unsupported = $this->bodyContract(['text/plain' => []])
            ->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'text/plain'], 'hello'));
        Assert::same($unsupported->violations[0]->message, 'Request media type "text/plain" is not supported');
    }

    public function readsTheBodyFromTheStreamStart(): void
    {
        $contract = $this->bodyContract(['application/json' => ['schema' => ['type' => 'object']]]);
        $request = new ServerRequest('POST', '/b', ['Content-Type' => 'application/json'], '{}');
        $request->getBody()->getContents();

        Assert::true($contract->validateRequest($request)->isValid());
    }

    public function skipsMalformedContentEntriesWhenMatching(): void
    {
        $result = $this->bodyContract([0 => ['schema' => ['type' => 'string']], 'application/json' => ['schema' => ['type' => 'object']]])
            ->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'application/json'], '{}'));

        Assert::true($result->isValid());
    }

    public function matchesTypeWildcardAndSuffixDeclarations(): void
    {
        $request = new ServerRequest('POST', '/b', ['Content-Type' => 'application/vnd.pet+json'], '{}');
        $json = new ServerRequest('POST', '/b', ['Content-Type' => 'application/json'], '{}');

        Assert::true($this->bodyContract(['application/*' => ['schema' => ['type' => 'object']]])->validateRequest($request)->isValid());
        Assert::true($this->bodyContract(['application/*+json' => ['schema' => ['type' => 'object']]])->validateRequest($request)->isValid());
        Assert::same($this->bodyContract(['application/*+json' => ['schema' => ['type' => 'object']]])->validateRequest($json)->violations[0]->code, 'request.body.media_type');
        Assert::same($this->bodyContract(['text/*' => ['schema' => ['type' => 'object']]])->validateRequest($request)->violations[0]->code, 'request.body.media_type');
        Assert::true($this->bodyContract(['application/json ; charset=utf-8' => ['schema' => ['type' => 'object']]])->validateRequest($json)->isValid());
        Assert::true($this->bodyContract(['Application/JSON' => ['schema' => ['type' => 'object']]])->validateRequest($json)->isValid());
    }

    public function typeOnlyDeclarationsAndActualsFailClosed(): void
    {
        $contract = $this->bodyContract(['text' => [], 'application/json' => ['schema' => ['type' => 'object']]]);

        Assert::true($contract->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'application/json'], '{}'))->isValid());
        Assert::same($contract->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'weird'], 'x'))->violations[0]->code, 'request.body.media_type');
    }

    public function honorsTheBodyJsonDepthBudget(): void
    {
        $contract = $this->bodyContract(['application/json' => ['schema' => ['type' => 'array']]]);
        $deep = static fn(int $count): string => str_repeat('[', $count) . '1' . str_repeat(']', $count);

        Assert::true($contract->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'application/json'], $deep(63)))->isValid());
        Assert::same($contract->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'application/json'], $deep(64)))->violations[0]->code, 'request.body.json');
    }

    public function validatesFormUrlencodedBodiesUsingSchemaTypes(): void
    {
        $contract = $this->bodyContract(['application/x-www-form-urlencoded' => ['schema' => [
            'type' => 'object',
            'required' => ['name', 'age'],
            'properties' => ['name' => ['type' => 'string'], 'age' => ['type' => 'integer']],
        ]]]);

        Assert::true($contract->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'application/x-www-form-urlencoded'], 'name=Jane&age=37'))->isValid());
        Assert::same($contract->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'application/x-www-form-urlencoded'], 'name=Jane&age=nope'))->violations[0]->code, 'request.body.schema');
    }

    public function validatesMultipartJsonAndBinaryParts(): void
    {
        $boundary = 'test-boundary';
        $body = '--' . $boundary . "\r\n"
            . "Content-Disposition: form-data; name=\"meta\"\r\n"
            . "Content-Type: application/json\r\n\r\n"
            . '{"id":7}' . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"a.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\n"
            . 'hello' . "\r\n"
            . '--' . $boundary . "--\r\n";
        $contract = $this->bodyContract(['multipart/form-data' => ['schema' => [
            'type' => 'object',
            'required' => ['meta', 'file'],
            'properties' => [
                'meta' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]],
                'file' => ['type' => 'string'],
            ],
        ], 'encoding' => ['meta' => ['contentType' => 'application/json']]]]);

        $request = new ServerRequest('POST', '/b', ['Content-Type' => 'multipart/form-data; boundary="' . $boundary . '"'], $body);
        Assert::true($contract->validateRequest($request)->isValid());
    }

    public function defaultsAnArrayPartContentTypeToItsItemType(): void
    {
        $boundary = 'test-boundary';
        $contract = $this->bodyContract(['multipart/form-data' => ['schema' => [
            'type' => 'object',
            'required' => ['ids', 'rows'],
            'properties' => [
                'ids' => ['type' => 'array', 'minItems' => 2, 'uniqueItems' => true, 'items' => ['type' => 'integer']],
                'rows' => ['type' => 'array', 'minItems' => 2, 'items' => ['type' => 'array', 'items' => ['type' => 'integer']]],
            ],
        ]]]);
        $part = static fn(string $name, string $type, string $value): string => '--' . $boundary . "\r\n"
            . "Content-Disposition: form-data; name=\"" . $name . "\"\r\n"
            . 'Content-Type: ' . $type . "\r\n\r\n"
            . $value . "\r\n";
        $request = static fn(string $body): ServerRequest => new ServerRequest('POST', '/b', ['Content-Type' => 'multipart/form-data; boundary="' . $boundary . '"'], $body . '--' . $boundary . "--\r\n");

        Assert::true($contract->validateRequest($request($part('ids', 'text/plain', '1') . $part('ids', 'text/plain', '2') . $part('rows', 'application/json', '[[1,2],[]]')))->isValid());
        Assert::same($contract->validateRequest($request($part('ids', 'text/plain', '1') . $part('ids', 'text/plain', '1') . $part('rows', 'application/json', '[[1],[2]]')))->violations[0]->code, 'request.body.schema');
        Assert::same($contract->validateRequest($request($part('ids', 'text/plain', '1') . $part('rows', 'application/json', '[[1],[2]]')))->violations[0]->code, 'request.body.schema');
        Assert::same($contract->validateRequest($request($part('ids', 'application/json', '[1,2]') . $part('rows', 'application/json', '[1]')))->violations[0]->message, 'Multipart property "ids" has content type "application/json", expected "text/plain"');
        Assert::same($contract->validateRequest($request($part('ids', 'text/plain', '1') . $part('rows', 'text/plain', '1')))->violations[0]->message, 'Multipart property "rows" has content type "text/plain", expected "application/json"');
    }

    public function rejectsMalformedMultipartBodies(): void
    {
        $contract = $this->bodyContract(['multipart/form-data' => ['schema' => ['type' => 'object']]]);
        $result = $contract->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'multipart/form-data'], 'body'));

        Assert::same($result->violations[0]->code, 'request.body.decode');
    }

    public function rejectsMalformedBodySchemas(): void
    {
        try {
            $this->bodyContract(['application/json' => ['schema' => [['type' => 'string']]]])
                ->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'application/json'], '{}'));
            Assert::true(actual: false);
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), 'Schema must be an object');
        }

        try {
            $this->bodyContract(['application/json' => ['schema' => [0 => ['type' => 'string'], 'a' => 1]]])
                ->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'application/json'], '{}'));
            Assert::true(actual: false);
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), 'Schema keys must be strings');
        }
    }

    public function validatesEverySupportedParameterStyle(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/s/{l}/{m}' => ['get' => [
            'parameters' => [
                ['name' => 'l', 'in' => 'path', 'required' => true, 'style' => 'label', 'schema' => ['type' => 'integer']],
                ['name' => 'm', 'in' => 'path', 'required' => true, 'style' => 'matrix', 'schema' => ['type' => 'integer']],
                ['name' => 'sd', 'in' => 'query', 'style' => 'spaceDelimited', 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'integer']]],
                ['name' => 'pd', 'in' => 'query', 'style' => 'pipeDelimited', 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'integer']]],
                ['name' => 'do', 'in' => 'query', 'style' => 'deepObject', 'explode' => true, 'schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]]],
            ],
            'responses' => ['200' => []],
        ]]]]);

        $request = new ServerRequest('GET', '/s/.7/;m=9?sd=1&pd=2&do%5Ba%5D=5');
        Assert::true($contract->validateRequest($request)->isValid());
    }

    /** @param array<string, mixed> $parameter */
    private function paramContract(array $parameter): Contract
    {
        return Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/q' => ['get' => [
            'parameters' => [$parameter],
            'responses' => ['200' => []],
        ]]]]);
    }

    /** @param array<array-key, mixed> $content */
    private function bodyContract(array $content): Contract
    {
        return Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/b' => ['post' => [
            'requestBody' => ['required' => true, 'content' => $content],
            'responses' => ['204' => []],
        ]]]]);
    }

    private function contract(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => [
                '/pets/{id}' => [
                    'post' => [
                        'operationId' => 'pets.update',
                        'parameters' => [
                            [
                                'name' => 'id',
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'integer', 'minimum' => 1],
                            ],
                            [
                                'name' => 'tag',
                                'in' => 'query',
                                'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                            [
                                'name' => 'X-Tenant',
                                'in' => 'header',
                                'required' => true,
                                'schema' => ['type' => 'string'],
                            ],
                        ],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['name'],
                                        'properties' => ['name' => ['type' => 'string']],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['200' => []],
                    ],
                ],
            ],
        ]);
    }
}
