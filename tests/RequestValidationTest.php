<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\ContractViolation;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaDialect;
use Rasuvaeff\OpenApiContract\Internal\Validation\BodyDecodingFailed;
use Rasuvaeff\OpenApiContract\Internal\Validation\FormUrlencodedBodyDecoder;
use Rasuvaeff\OpenApiContract\Internal\Validation\MessageBodyTooLarge;
use Rasuvaeff\OpenApiContract\Internal\Validation\MessageBodyUnreadable;
use Rasuvaeff\OpenApiContract\Internal\Validation\MultipartBodyDecoder;
use Rasuvaeff\OpenApiContract\Internal\Validation\OpaqueBodyVerdict;
use Rasuvaeff\OpenApiContract\Internal\Validation\RequestValidator;
use Rasuvaeff\OpenApiContract\Internal\Validation\SchemaValueDecoder;
use Rasuvaeff\OpenApiContract\InvalidContract;
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
#[Covers(BodyDecodingFailed::class)]
#[Covers(FormUrlencodedBodyDecoder::class)]
#[Covers(MessageBodyTooLarge::class)]
#[Covers(MessageBodyUnreadable::class)]
#[Covers(MultipartBodyDecoder::class)]
#[Covers(OpaqueBodyVerdict::class)]
#[Covers(SchemaValueDecoder::class)]
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

    public function reportsAStreamThatMakesNoProgressAsAViolation(): void
    {
        $stream = Understudy::for(StreamInterface::class);
        when(static fn(): bool => $stream->isSeekable())->returns(true);
        when(static fn(): int => $stream->tell())->returns(0);
        when(static fn(): bool => $stream->eof())->returns(false);
        when(static fn(): string => $stream->read(8192))->returns('');
        expect(static fn() => $stream->seek(0));
        $request = (new ServerRequest('POST', '/b', ['Content-Type' => 'application/json']))->withBody($stream);

        // A body that cannot be read is a fact about the message, like a
        // non-seekable stream and one over the byte budget right next to it.
        // It used to leave the public validate method as a bare
        // `\RuntimeException`.
        $result = $this->bodyContract(['application/json' => ['schema' => ['type' => 'object']]])->validateRequest($request);

        Assert::same($result->violations[0]->code, 'request.body.unreadable');
        Assert::same($result->violations[0]->message, 'Request body stream could not be read');
        Assert::same(count($result->violations), 1);
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

    /**
     * The separator arrives percent-encoded from any conforming client, so
     * splitting on the raw octet alone reported a one-element array and failed
     * a valid request.
     */
    #[DataProvider('delimitedQueryWiresProvider')]
    public function acceptsDelimitedQueryParametersInTheirEncodedWireForm(string $style, string $query): void
    {
        $contract = $this->paramContract([
            'name' => 'color', 'in' => 'query', 'required' => true, 'style' => $style, 'explode' => false,
            'schema' => ['type' => 'array', 'minItems' => 3, 'items' => ['type' => 'string']],
        ]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/q?' . $query))->isValid());
    }

    /** @return iterable<string, array{string, string}> */
    public static function delimitedQueryWiresProvider(): iterable
    {
        yield 'space encoded' => ['spaceDelimited', 'color=blue%20black%20brown'];
        yield 'space raw' => ['spaceDelimited', 'color=blue black brown'];
        yield 'pipe raw' => ['pipeDelimited', 'color=blue|black|brown'];
        yield 'pipe encoded' => ['pipeDelimited', 'color=blue%7Cblack%7Cbrown'];
    }

    /**
     * An unexploded label array is comma-separated; only the exploded form
     * repeats the dot.
     */
    #[DataProvider('labelPathWiresProvider')]
    public function acceptsLabelPathParametersInBothExplodeForms(bool $explode, string $segment): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/c/{color}' => ['get' => [
            'parameters' => [[
                'name' => 'color', 'in' => 'path', 'required' => true, 'style' => 'label', 'explode' => $explode,
                'schema' => ['type' => 'array', 'minItems' => 3, 'items' => ['type' => 'string']],
            ]],
            'responses' => ['200' => []],
        ]]]]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/c/' . $segment))->isValid());
    }

    /** @return iterable<string, array{bool, string}> */
    public static function labelPathWiresProvider(): iterable
    {
        yield 'unexploded' => [false, '.blue,black,brown'];
        yield 'exploded' => [true, '.blue.black.brown'];
    }

    /**
     * PSR-7 joins repeated header lines with ", " and RFC 9110 allows the
     * whitespace anyway, so a list read from a header carries it whichever way
     * the client sent the value. The response direction has always stripped it.
     */
    #[DataProvider('headerListWiresProvider')]
    public function stripsTheWhitespaceAroundAHeaderListSeparator(array $headers): void
    {
        $contract = $this->paramContract([
            'name' => 'X-Tags', 'in' => 'header', 'required' => true, 'style' => 'simple', 'explode' => false,
            'schema' => ['type' => 'array', 'minItems' => 2, 'items' => ['type' => 'string', 'pattern' => '^[a-z]+\z']],
        ]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/q', $headers))->isValid());
    }

    /** @return iterable<string, array{array<string, string|list<string>>}> */
    public static function headerListWiresProvider(): iterable
    {
        yield 'no whitespace' => [['X-Tags' => 'red,blue']];
        yield 'whitespace after the comma' => [['X-Tags' => 'red, blue']];
        yield 'repeated header lines' => [['X-Tags' => ['red', 'blue']]];
    }

    /**
     * A header field value is opaque octets to HTTP, and no client
     * percent-encodes one. We used to decode it anyway, which rewrote values
     * the application receives intact — `X-Path: /a%20b` became `/a b`, and a
     * `50%` discount became a broken escape. It is read as sent now, so the
     * schema sees the bytes the server sees (#66).
     *
     * The path and the query keep their decoding: they are built from RFC 3986
     * delimiters, so a value carrying one has to be escaped, and decoding is
     * the inverse of what every client does.
     */
    #[DataProvider('verbatimHeaderProvider')]
    public function readsAHeaderValueAsItWasSent(string $sent, string $expected): void
    {
        // `const` admits exactly one string, so accepting the request is a
        // statement about which one was read — not merely that something of a
        // permitted shape arrived.
        $contract = $this->paramContract([
            'name' => 'X-Trace', 'in' => 'header', 'required' => true,
            'schema' => ['type' => 'string', 'const' => $expected],
        ]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/q', ['X-Trace' => $sent]))->isValid());
    }

    /** @return iterable<string, array{string, string}> */
    public static function verbatimHeaderProvider(): iterable
    {
        yield 'a percent-escape is not an escape' => ['/a%20b', '/a%20b'];
        yield 'a lone percent is a percent' => ['50%', '50%'];
        yield 'a plus is a plus' => ['a+b', 'a+b'];
        yield 'a space is a space' => ['a b', 'a b'];
        yield 'multibyte percent-escapes stay text' => ['%C4%8B', '%C4%8B'];
    }

    /**
     * The price of reading a header as sent, named rather than discovered: the
     * style's own delimiter no longer has an escape, so a member carrying one
     * splits. Percent-decoding used to hide this, at the cost of corrupting
     * every value that was never encoded.
     */
    public function aCommaInsideAHeaderMemberCannotBeEscaped(): void
    {
        $oneMember = $this->paramContract([
            'name' => 'X-Tags', 'in' => 'header', 'required' => true, 'style' => 'simple', 'explode' => false,
            'schema' => ['type' => 'array', 'const' => ['a%2Cb']],
        ]);
        $twoMembers = $this->paramContract([
            'name' => 'X-Tags', 'in' => 'header', 'required' => true, 'style' => 'simple', 'explode' => false,
            'schema' => ['type' => 'array', 'const' => ['a', 'b']],
        ]);

        // The escape is not honoured, so it is not an escape: the value stays
        // one member and keeps the three characters literally.
        Assert::true($oneMember->validateRequest(new ServerRequest('GET', '/q', ['X-Tags' => 'a%2Cb']))->isValid());
        // And a literal comma still separates, which leaves no spelling at all
        // for a member that contains one.
        Assert::true($twoMembers->validateRequest(new ServerRequest('GET', '/q', ['X-Tags' => 'a,b']))->isValid());
    }

    /**
     * An open object cannot tell an undeclared query pair from a stray one, but
     * a pair another parameter declares is certainly not part of it.
     */
    public function anOpenObjectParameterLeavesTheOtherParametersAlone(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/q' => ['get' => [
            'parameters' => [
                [
                    'name' => 'filter', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true,
                    'schema' => ['type' => 'object', 'maxProperties' => 2, 'additionalProperties' => ['type' => 'string']],
                ],
                ['name' => 'page', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']],
            ],
            'responses' => ['200' => []],
        ]]]]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/q?a=1&b=2&page=3'))->isValid());
    }

    /**
     * A boolean member of `properties` used to raise a bare
     * `InvalidArgumentException` out of `validateRequest()` — not a violation,
     * not an `InvalidContract` — because the body decoders read `properties`
     * through a helper that only accepted object schemas. The same document
     * shape was silently accepted for a JSON body, so the two encodings were
     * wrong in two different directions at once.
     */
    #[DataProvider('booleanPropertyBodyProvider')]
    public function validatesBooleanPropertySchemasInEveryBodyEncoding(string $mediaType, string $body, bool $valid): void
    {
        $schema = ['type' => 'object', 'properties' => ['open' => true, 'secret' => false]];
        $contract = $this->bodyContract([$mediaType => ['schema' => $schema]]);
        $request = new ServerRequest('POST', '/b', ['Content-Type' => $mediaType], $body);

        Assert::same($contract->validateRequest($request)->isValid(), $valid);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function booleanPropertyBodyProvider(): iterable
    {
        yield 'json accepts the open property' => ['application/json', '{"open":1}', true];
        yield 'json rejects the forbidden property' => ['application/json', '{"secret":1}', false];
        yield 'form accepts the open property' => ['application/x-www-form-urlencoded', 'open=1', true];
        yield 'form rejects the forbidden property' => ['application/x-www-form-urlencoded', 'secret=1', false];
        yield 'multipart accepts the open property' => [
            'multipart/form-data; boundary=X',
            "--X\r\nContent-Disposition: form-data; name=\"open\"\r\n\r\n1\r\n--X--\r\n",
            true,
        ];
        yield 'multipart rejects the forbidden property' => [
            'multipart/form-data; boundary=X',
            "--X\r\nContent-Disposition: form-data; name=\"secret\"\r\n\r\n1\r\n--X--\r\n",
            false,
        ];
    }

    /**
     * A numeric property name is a name, not a malformed key: PHP normalizes
     * `"2020"` to an integer array key, and dropping it took the property, its
     * subschema and its `required` entry out of the check entirely.
     */
    public function validatesPropertiesWhoseNameIsNumericInABody(): void
    {
        $contract = $this->bodyContract(['application/json' => ['schema' => [
            'type' => 'object', 'properties' => ['2020' => ['type' => 'integer']], 'required' => ['2020'],
        ]]]);
        $request = static fn(string $body): ServerRequest => new ServerRequest('POST', '/b', ['Content-Type' => 'application/json'], $body);

        Assert::true($contract->validateRequest($request('{"2020":1}'))->isValid());
        Assert::same($contract->validateRequest($request('{}'))->violations[0]->code, 'request.body.schema');
        Assert::same($contract->validateRequest($request('{"2020":"x"}'))->violations[0]->code, 'request.body.schema');
    }

    /**
     * OAS 3.0.3: "additionalProperties — Value can be boolean or object".
     * Rejecting the boolean form made the commonest closed-object idiom of
     * the 3.0 corpus throw out of the first validation call, after the
     * document had already loaded.
     */
    public function acceptsBooleanAdditionalPropertiesInAnOas30Document(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.0.3', 'paths' => ['/b' => ['post' => [
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                'type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'additionalProperties' => false,
            ]]]],
            'responses' => ['204' => []],
        ]]]]);
        $request = static fn(string $body): ServerRequest => new ServerRequest('POST', '/b', ['Content-Type' => 'application/json'], $body);

        Assert::true($contract->validateRequest($request('{"a":"x"}'))->isValid());
        Assert::same($contract->validateRequest($request('{"a":"x","b":1}'))->violations[0]->code, 'request.body.schema');
    }

    /**
     * A form property the document declares a `contentType` for does not
     * travel as a serialized parameter: its single pair carries a whole
     * document in that media type. Reading it as a parameter meant a property
     * declared `application/json` arrived as a string, failed its own object
     * schema, and was reported as `request.body.schema` — a valid request
     * called invalid, with a message pointing away from the cause. The
     * multipart decoder has always honoured `contentType`; this one ignored
     * it without even rejecting it.
     */
    #[DataProvider('formContentTypeProvider')]
    public function honoursEncodingContentTypeOnAFormProperty(string $body, ?string $violation): void
    {
        $contract = $this->bodyContract(['application/x-www-form-urlencoded' => [
            'schema' => [
                'type' => 'object',
                'required' => ['meta'],
                'properties' => ['meta' => ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'integer']]]],
            ],
            'encoding' => ['meta' => ['contentType' => 'application/json']],
        ]]);
        $result = $contract->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'application/x-www-form-urlencoded'], $body));

        Assert::same($result->isValid(), $violation === null);
        if ($violation !== null) {
            Assert::same($result->violations[0]->code, $violation);
        }
    }

    /** @return iterable<string, array{string, null|string}> */
    public static function formContentTypeProvider(): iterable
    {
        yield 'json object decodes' => ['meta=' . rawurlencode('{"a":1}'), null];
        yield 'json object failing its schema' => ['meta=' . rawurlencode('{"a":"x"}'), 'request.body.schema'];
        yield 'malformed json' => ['meta=' . rawurlencode('{'), 'request.body.decode'];
        yield 'repeated property' => ['meta=%7B%7D&meta=%7B%7D', 'request.body.decode'];
    }

    /**
     * A non-JSON `contentType` leaves the value as the string it already is —
     * as far as an undecoded payload can be judged, the same rule a whole
     * opaque body follows.
     */
    public function readsANonJsonFormContentTypeAsAString(): void
    {
        $contract = $this->bodyContract(['application/x-www-form-urlencoded' => [
            'schema' => ['type' => 'object', 'required' => ['note'], 'properties' => ['note' => ['type' => 'string', 'minLength' => 2]]],
            'encoding' => ['note' => ['contentType' => 'text/plain']],
        ]]);
        $request = static fn(string $body): ServerRequest => new ServerRequest('POST', '/b', ['Content-Type' => 'application/x-www-form-urlencoded'], $body);

        Assert::true($contract->validateRequest($request('note=hello'))->isValid());
        Assert::same($contract->validateRequest($request('note=h'))->violations[0]->code, 'request.body.schema');
    }

    /**
     * A Header Object declared for a multipart part was checked for presence
     * and nothing else, so a constraint the document states was enforced by
     * nothing — while the same declaration on a response header was fully
     * validated.
     */
    #[DataProvider('multipartHeaderProvider')]
    public function validatesDeclaredMultipartPartHeaders(string $headers, bool $valid): void
    {
        $contract = $this->bodyContract(['multipart/form-data' => [
            'schema' => ['type' => 'object', 'required' => ['note'], 'properties' => ['note' => ['type' => 'string']]],
            'encoding' => ['note' => ['headers' => [
                'X-Chunk' => ['required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1]],
                'X-Tag' => ['schema' => ['type' => 'string', 'enum' => ['a', 'b']]],
            ]]],
        ]]);
        $payload = "--X\r\nContent-Disposition: form-data; name=\"note\"\r\n" . $headers . "\r\nhi\r\n--X--\r\n";
        $request = new ServerRequest('POST', '/b', ['Content-Type' => 'multipart/form-data; boundary=X'], $payload);

        Assert::same($contract->validateRequest($request)->isValid(), $valid);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function multipartHeaderProvider(): iterable
    {
        yield 'required header present and valid' => ["X-Chunk: 3\r\n", true];
        yield 'optional header valid' => ["X-Chunk: 3\r\nX-Tag: a\r\n", true];
        yield 'optional header absent' => ["X-Chunk: 1\r\n", true];
        yield 'required header missing' => ['', false];
        yield 'required header below its minimum' => ["X-Chunk: 0\r\n", false];
        yield 'required header of the wrong type' => ["X-Chunk: many\r\n", false];
        yield 'optional header outside its enum' => ["X-Chunk: 1\r\nX-Tag: z\r\n", false];
    }

    /**
     * A query string is `application/x-www-form-urlencoded` content, where
     * `+` spells a space. Percent-decoding it literally made the validator
     * report a violation for the exact value the application behind it
     * receives as correct — and disagree with its own form body decoder,
     * which has always folded `+` first.
     *
     * The decoded value is pinned by the schema rather than inspected: each
     * case only passes if the parameter deserialized to exactly the listed
     * members.
     */
    #[DataProvider('formEncodedQueryProvider')]
    public function readsPlusAsASpaceInTheQuery(array $parameter, string $query): void
    {
        Assert::true($this->paramContract($parameter)->validateRequest(new ServerRequest('GET', '/q?' . $query))->isValid());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function formEncodedQueryProvider(): iterable
    {
        $scalar = static fn(string $value): array => [
            'name' => 'q', 'in' => 'query', 'required' => true,
            'schema' => ['type' => 'string', 'enum' => [$value]],
        ];
        $list = static fn(string $style, bool $explode, array $members): array => [
            'name' => 'q', 'in' => 'query', 'required' => true, 'style' => $style, 'explode' => $explode,
            'schema' => ['type' => 'array', 'minItems' => count($members), 'maxItems' => count($members),
                'items' => ['type' => 'string', 'enum' => $members]],
        ];

        yield 'scalar decodes to a space' => [$scalar('a b'), 'q=a+b'];
        yield 'a percent-encoded plus stays a plus' => [$scalar('a+b'), 'q=a%2Bb'];
        yield 'percent-encoded space is unchanged' => [$scalar('a b'), 'q=a%20b'];
        yield 'exploded list decodes each member' => [$list('form', true, ['a b', 'c']), 'q=a+b&q=c'];
        // The spaceDelimited separator *is* a space, so a "+" separates too.
        yield 'space delimited splits on a plus' => [$list('spaceDelimited', false, ['a', 'b']), 'q=a+b'];
    }

    /**
     * A key with no `=` carries the empty value, the way `parse_str()` and
     * every SAPI read it. An exploded object parameter is handed every pair a
     * sibling parameter does not claim, so a single stray `&flag` used to fail
     * an unrelated parameter's deserialization instead.
     */
    #[DataProvider('valuelessQueryKeyProvider')]
    public function readsAValuelessQueryKeyAsAnEmptyValue(string $query, bool $valid): void
    {
        $contract = $this->paramContract([
            'name' => 'o', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true,
            'schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
        ]);

        Assert::same($contract->validateRequest(new ServerRequest('GET', '/q?' . $query))->isValid(), $valid);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function valuelessQueryKeyProvider(): iterable
    {
        yield 'clean' => ['a=x', true];
        yield 'foreign valueless key' => ['a=x&flag', true];
        yield 'foreign valueless key first' => ['flag&a=x', true];
        yield 'the required property itself is valueless' => ['a', true];
        yield 'still reports the missing property' => ['flag', false];
    }

    /**
     * RFC 2046 §5.1.1: the CRLF after the closing delimiter is optional, and
     * an epilogue may follow it. A body with no parts at all stays rejected —
     * the same clause requires at least one.
     */
    #[DataProvider('multipartClosingProvider')]
    public function acceptsEveryLegalMultipartClosingDelimiter(string $payload, bool $valid): void
    {
        $contract = $this->bodyContract(['multipart/form-data' => ['schema' => [
            'type' => 'object', 'required' => ['note'], 'properties' => ['note' => ['type' => 'string']],
        ]]]);
        $request = new ServerRequest('POST', '/b', ['Content-Type' => 'multipart/form-data; boundary=X'], $payload);

        Assert::same($contract->validateRequest($request)->isValid(), $valid);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function multipartClosingProvider(): iterable
    {
        $part = "--X\r\nContent-Disposition: form-data; name=\"note\"\r\n\r\nhi\r\n";

        yield 'closing delimiter with CRLF' => [$part . "--X--\r\n", true];
        yield 'closing delimiter without CRLF' => [$part . '--X--', true];
        yield 'closing delimiter with an epilogue' => [$part . "--X--\r\nbye\r\n", true];
        yield 'no parts at all' => ["--X--\r\n", false];
        yield 'garbage after the closing delimiter' => [$part . '--X--junk', false];
        yield 'unterminated body' => [$part, false];
    }

    /**
     * OAS 3.1 spells a type as a union, and the wire shape follows the union's
     * membership rather than its identity.
     */
    public function readsTheWireShapeOfATypeUnion(): void
    {
        $contract = $this->paramContract([
            'name' => 'tags', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => false,
            'schema' => ['type' => ['array', 'null'], 'minItems' => 2, 'items' => ['type' => 'string']],
        ]);

        Assert::true($contract->validateRequest(new ServerRequest('GET', '/q?tags=red,blue'))->isValid());
    }

    /**
     * `explode: false` puts the whole array in one part as a comma-separated
     * list; the form decoder has always read that, the multipart one had not.
     */
    #[DataProvider('multipartExplodeProvider')]
    public function readsTheEncodingExplodeOfAMultipartArray(bool $explode, string $parts): void
    {
        $contract = $this->bodyContract(['multipart/form-data' => [
            'schema' => ['type' => 'object', 'required' => ['tags'], 'properties' => [
                'tags' => ['type' => 'array', 'minItems' => 3, 'items' => ['type' => 'string', 'maxLength' => 4]],
            ]],
            'encoding' => ['tags' => ['explode' => $explode]],
        ]]);
        $request = new ServerRequest('POST', '/b', ['Content-Type' => 'multipart/form-data; boundary=X'], $parts);

        Assert::true($contract->validateRequest($request)->isValid());
    }

    /** @return iterable<string, array{bool, string}> */
    public static function multipartExplodeProvider(): iterable
    {
        $part = static fn(string $value): string => "--X\r\nContent-Disposition: form-data; name=\"tags\"\r\n\r\n" . $value . "\r\n";
        yield 'unexploded, one part' => [false, $part('red,blue,pink') . "--X--\r\n"];
        yield 'exploded, repeated parts' => [true, $part('red') . $part('blue') . $part('pink') . "--X--\r\n"];
    }

    /**
     * A boolean schema is a schema: `false` admits nothing at all. Reading it
     * as "no schema declared" let every body through.
     */
    public function aFalseBodySchemaAdmitsNothing(): void
    {
        $result = $this->bodyContract(['application/json' => ['schema' => false]])
            ->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'application/json'], '{}'));

        Assert::same($result->violations[0]->code, 'request.body.schema');
    }

    public function aTrueBodySchemaConstrainsNothing(): void
    {
        $result = $this->bodyContract(['application/json' => ['schema' => true]])
            ->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'application/json'], '{"any":"thing"}'));

        Assert::true($result->isValid());
    }

    /**
     * A Path Item's parameters and an Operation's are merged for lookup but
     * live at different pointers; a position in the merged list points at a
     * declaration the reader cannot find.
     */
    public function parameterPointersNameTheDeclarationThatCarriesThem(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/p' => [
            'parameters' => [['name' => 'a', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']]],
            'get' => [
                'parameters' => [['name' => 'b', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']]],
                'responses' => ['200' => []],
            ],
        ]]]);

        $result = $contract->validateRequest(new ServerRequest('GET', '/p'));

        Assert::same(
            array_map(static fn(Violation $violation): string => $violation->specPointer, $result->violations),
            ['/paths/~1p/parameters/0', '/paths/~1p/get/parameters/0'],
        );
    }

    /**
     * `InvalidContract` extends `InvalidArgumentException`, which this loop
     * catches to turn a deserialization failure into a violation. Without the
     * narrower catch, an unsupported document is reported as something the
     * request did wrong.
     */
    public function anUnsupportedSchemaRaisesInsteadOfBecomingAViolation(): void
    {
        $contract = $this->paramContract([
            'name' => 'q', 'in' => 'query', 'required' => true,
            'schema' => ['type' => 'string', '$anchor' => 'x'],
        ]);

        // Called directly as well as through the facade: the mutation mapping
        // follows pcov's line coverage, and the validator is reached from
        // Contract through a call graph that attributes the mutant elsewhere.
        foreach ([
            fn(): mixed => $contract->validateRequest(new ServerRequest('GET', '/q?q=x')),
            fn(): mixed => (new RequestValidator())->validate(
                $contract->match(new ServerRequest('GET', '/q?q=x')) ?? throw new \LogicException('No operation matched'),
                new ServerRequest('GET', '/q?q=x'),
                SchemaDialect::OpenApi31,
            ),
        ] as $call) {
            try {
                $call();
                Assert::true(actual: false, message: 'Expected an unsupported schema');
            } catch (ContractViolation $exception) {
                Assert::true(actual: false, message: 'Contract errors must not be reported as violations: ' . $exception->getMessage());
            } catch (InvalidContract $exception) {
                Assert::same($exception->getMessage(), 'Unsupported schema keyword "$anchor": reference identity is outside the support matrix');
            }
        }
    }

    /**
     * A wire the codec cannot deserialize is the request's fault, and is
     * reported rather than raised — the counterpart of the contract error
     * above. Nothing asserted this code before, so the branch was only ever
     * reached, never checked.
     */
    public function anUndeserializableWireIsReportedAsASerializationViolation(): void
    {
        $contract = $this->paramContract([
            'name' => 'o', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => false,
            'schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
        ]);

        $violation = $contract->validateRequest(new ServerRequest('GET', '/q?o=a,1,odd'))->violations[0];

        Assert::same($violation->code, 'request.parameter.serialization');
        Assert::same($violation->message, 'Query parameter "o" cannot be deserialized');
        Assert::same($violation->instancePath, 'o');
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

        $unsupported = $this->bodyContract(['application/xml' => ['schema' => ['type' => 'object']]])
            ->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'application/xml'], '<a/>'));
        Assert::same($unsupported->violations[0]->code, 'request.body.unsupported');
        Assert::same($unsupported->violations[0]->message, 'Request media type "application/xml" cannot be validated against a non-string schema');
        Assert::same($unsupported->violations[0]->actual, 'application/xml');
        Assert::same(count($unsupported->violations), 1);
    }

    public function validatesDeclaredNonJsonBodiesAsFarAsTheSchemaAllows(): void
    {
        $plain = fn(string $body): ServerRequest => new ServerRequest('POST', '/b', ['Content-Type' => 'text/plain; charset=utf-8'], $body);

        Assert::true($this->bodyContract(['text/plain' => []])->validateRequest($plain('hello'))->isValid());
        Assert::true($this->bodyContract(['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]])
            ->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'application/octet-stream'], "\x00\xff"))->isValid());
        Assert::true($this->bodyContract(['text/plain' => ['schema' => ['type' => ['string', 'null'], 'maxLength' => 5]]])->validateRequest($plain('hello'))->isValid());

        $tooLong = $this->bodyContract(['text/plain' => ['schema' => ['type' => 'string', 'maxLength' => 3]]])->validateRequest($plain('hello'));
        Assert::same($tooLong->violations[0]->code, 'request.body.schema');
        Assert::same($tooLong->violations[0]->actual, 'hello');
        Assert::same(count($tooLong->violations), 1);

        $enum = $this->bodyContract(['text/plain' => ['schema' => ['enum' => ['on', 'off']]]])->validateRequest($plain('hello'));
        Assert::same($enum->violations[0]->code, 'request.body.unsupported');
        $union = $this->bodyContract(['text/plain' => ['schema' => ['type' => ['string', 'integer']]]])->validateRequest($plain('hello'));
        Assert::same($union->violations[0]->code, 'request.body.unsupported');
    }

    public function readsTheBodyFromTheStreamStart(): void
    {
        $contract = $this->bodyContract(['application/json' => ['schema' => ['type' => 'object']]]);
        $request = new ServerRequest('POST', '/b', ['Content-Type' => 'application/json'], '{}');
        $request->getBody()->getContents();

        Assert::true($contract->validateRequest($request)->isValid());
    }

    /**
     * `?n=5&n=999` is a well-formed query whose meaning depends on the
     * runtime: PHP keeps the last occurrence in `parse_str()`, `$_GET` and
     * every PSR-7 `getQueryParams()`. Reading the first — which this did —
     * let a request satisfy the contract with one value and hand the
     * application another.
     */
    #[DataProvider('duplicateParameterProvider')]
    public function reportsAParameterThatOccursMoreThanOnce(array $parameter, string $target, ?string $code): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/q' => ['get' => [
            'parameters' => [$parameter],
            'responses' => ['204' => []],
        ]]]]);

        $result = $contract->validateRequest(new ServerRequest('GET', $target));

        Assert::same($result->violations[0]->code ?? null, $code);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string|null}> */
    public static function duplicateParameterProvider(): iterable
    {
        $scalar = ['name' => 'n', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer', 'maximum' => 10]];
        $list = ['name' => 'tags', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]];

        yield 'scalar sent once' => [$scalar, '/q?n=5', null];
        yield 'scalar sent twice' => [$scalar, '/q?n=5&n=999', 'request.parameter.duplicate'];
        yield 'scalar sent twice with the same value' => [$scalar, '/q?n=5&n=5', 'request.parameter.duplicate'];
        yield 'unexploded list sent twice' => [[...$list, 'explode' => false], '/q?tags=a,b&tags=c', 'request.parameter.duplicate'];
        // Repeating the name is what an exploded list means.
        yield 'exploded list repeats its name' => [[...$list, 'explode' => true], '/q?tags=a&tags=b', null];
        yield 'delimited list sent twice' => [
            [...$list, 'style' => 'pipeDelimited', 'explode' => false],
            '/q?tags=a|b&tags=c',
            'request.parameter.duplicate',
        ];
    }

    public function reportsADuplicateCookieParameter(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/c' => ['get' => [
            'parameters' => [['name' => 'sid', 'in' => 'cookie', 'required' => true, 'schema' => ['type' => 'string', 'maxLength' => 3]]],
            'responses' => ['204' => []],
        ]]]]);

        // PHP keeps the last cookie of a repeated name too.
        $result = $contract->validateRequest(new ServerRequest('GET', '/c', ['Cookie' => 'sid=abc; sid=abcdef']));

        Assert::same($result->violations[0]->code, 'request.parameter.duplicate');
        Assert::same($result->violations[0]->message, 'Cookie parameter "sid" occurs more than once');
    }

    public function rejectsMalformedContentEntries(): void
    {
        try {
            $this->bodyContract([0 => ['schema' => ['type' => 'string']], 'application/json' => ['schema' => ['type' => 'object']]]);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI content keys of requestBody of operation POST /b must be media type strings');
        }

        try {
            $this->bodyContract(['application/json' => 'invalid']);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI media type "application/json" of requestBody of operation POST /b must be an object');
        }
    }

    /**
     * A `content` map is a map, and OpenAPI gives its key order no meaning.
     * Taking the first key that matched let a `*\/*` entry above an exact one
     * decide the body's schema, so moving two lines changed what the document
     * said.
     */
    #[DataProvider('mediaTypeSpecificityProvider')]
    public function selectsTheMostSpecificDeclaredMediaType(array $content, string $body, bool $valid): void
    {
        $request = new ServerRequest('POST', '/b', ['Content-Type' => 'application/vnd.pet+json'], $body);

        Assert::same($this->bodyContract($content)->validateRequest($request)->isValid(), $valid);
    }

    /** @return iterable<string, array{array<string, mixed>, string, bool}> */
    public static function mediaTypeSpecificityProvider(): iterable
    {
        $exact = ['application/vnd.pet+json' => ['schema' => ['type' => 'object']]];
        $suffix = ['application/*+json' => ['schema' => ['type' => 'array']]];
        $subtype = ['application/*' => ['schema' => ['type' => 'integer']]];
        $any = ['*/*' => ['schema' => ['type' => 'string']]];

        yield 'exact wins over anything declared before it' => [[...$any, ...$subtype, ...$suffix, ...$exact], '{}', true];
        yield 'exact still decides what it rejects' => [[...$any, ...$exact], '"a string"', false];
        yield 'suffix wildcard wins over the subtype wildcard' => [[...$any, ...$subtype, ...$suffix], '[]', true];
        yield 'subtype wildcard wins over any' => [[...$any, ...$subtype], '7', true];
        yield 'any applies when nothing more specific is declared' => [$any, '"a string"', true];
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

    /**
     * A part's declared `contentType` is matched after normalization, so a
     * declaration carrying parameters or mixed case still matches the part
     * that arrives. Before the media-type helper was shared, the multipart
     * matcher was the one copy that skipped normalizing the declaration and
     * rejected this exchange.
     */
    public function normalizesADeclaredPartContentTypeBeforeMatching(): void
    {
        $boundary = 'test-boundary';
        $contract = $this->bodyContract(['multipart/form-data' => ['schema' => [
            'type' => 'object',
            'required' => ['note'],
            'properties' => ['note' => ['type' => 'string']],
        ], 'encoding' => ['note' => ['contentType' => 'Text/Plain; charset=utf-8']]]]);
        $body = '--' . $boundary . "\r\n"
            . "Content-Disposition: form-data; name=\"note\"\r\n"
            . "Content-Type: text/plain\r\n\r\n"
            . 'hello' . "\r\n"
            . '--' . $boundary . "--\r\n";

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

    /**
     * The form decoder reads a body by the declaration, not by guesswork:
     * which pairs belong to which property, which pairs are left over, and
     * how a name and a value come off the wire. Each case below fails
     * differently when one of those decisions is changed.
     */
    #[DataProvider('formBodyProvider')]
    public function decodesFormBodiesByTheirDeclaration(array $definition, string $body, ?string $code): void
    {
        $result = $this->bodyContract(['application/x-www-form-urlencoded' => $definition])
            ->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'application/x-www-form-urlencoded'], $body));

        Assert::same($result->violations[0]->code ?? null, $code);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string|null}> */
    public static function formBodyProvider(): iterable
    {
        $object = ['type' => 'object', 'properties' => ['role' => ['type' => 'string'], 'name' => ['type' => 'string']]];

        yield 'an exploded object property takes its own property names' => [
            ['schema' => [
                'type' => 'object',
                'properties' => ['user' => $object],
                'required' => ['user'],
                'additionalProperties' => false,
            ]],
            'role=admin&name=Ada',
            null,
        ];
        yield 'an unexploded object property takes its own name' => [
            [
                'schema' => ['type' => 'object', 'properties' => ['user' => $object], 'required' => ['user']],
                'encoding' => ['user' => ['explode' => false]],
            ],
            'user=role,admin,name,Ada',
            null,
        ];
        yield 'a property with no pairs is skipped, not the ones after it' => [
            ['schema' => [
                'type' => 'object',
                'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']],
                'required' => ['b'],
            ]],
            'b=7',
            null,
        ];
        yield 'a property after a content-type property is still decoded' => [
            [
                'schema' => [
                    'type' => 'object',
                    'properties' => ['doc' => ['type' => 'object'], 'n' => ['type' => 'integer']],
                    'required' => ['doc', 'n'],
                ],
                'encoding' => ['doc' => ['contentType' => 'application/json']],
            ],
            'doc=%7B%22k%22%3A1%7D&n=7',
            null,
        ];
        yield 'a left-over pair is decoded by additionalProperties' => [
            ['schema' => [
                'type' => 'object',
                'properties' => ['a' => ['type' => 'string']],
                'required' => ['a', 'extra'],
                'additionalProperties' => ['type' => 'integer'],
            ]],
            'a=x&extra=7',
            null,
        ];
        yield 'a pair consumed by a property is not left over as well' => [
            ['schema' => [
                'type' => 'object',
                'properties' => ['user' => $object],
                'required' => ['user'],
                'additionalProperties' => ['type' => 'integer'],
            ]],
            'role=admin&name=Ada',
            null,
        ];
        yield 'plus and percent decode in both the name and the value' => [
            ['schema' => [
                'type' => 'object',
                'properties' => ['a b' => ['type' => 'string', 'const' => 'c d']],
                'required' => ['a b'],
            ]],
            'a+b=c+d',
            null,
        ];
        yield 'only the first equals sign splits a pair' => [
            ['schema' => [
                'type' => 'object',
                'properties' => ['x' => ['const' => '1=2']],
                'required' => ['x'],
            ]],
            'x=1=2',
            null,
        ];
        yield 'a pair without a value decodes to an empty string' => [
            ['schema' => [
                'type' => 'object',
                'properties' => ['flag' => ['type' => 'string', 'const' => '']],
                'required' => ['flag'],
            ]],
            'flag',
            null,
        ];
        yield 'an unsupported encoding style fails closed' => [
            [
                'schema' => ['type' => 'object', 'properties' => ['doc' => ['type' => 'string']]],
                'encoding' => ['doc' => ['style' => 'deepObject']],
            ],
            'doc=x',
            'request.body.decode',
        ];
        yield 'a non-boolean encoding explode fails closed' => [
            [
                'schema' => ['type' => 'object', 'properties' => ['doc' => ['type' => 'string']]],
                'encoding' => ['doc' => ['explode' => 'yes']],
            ],
            'doc=x',
            'request.body.decode',
        ];
        yield 'an empty encoding contentType fails closed' => [
            [
                'schema' => ['type' => 'object', 'properties' => ['doc' => ['type' => 'string']]],
                'encoding' => ['doc' => ['contentType' => '']],
            ],
            'doc=x',
            'request.body.decode',
        ];
    }

    /**
     * Every way a multipart body can be malformed, and the boundaries of the
     * limits that decide it. Each message is pinned because the decoder is
     * fail-closed by design: a guard that stops guarding is invisible unless
     * something asserts what it said.
     */
    #[DataProvider('multipartFramingProvider')]
    public function reportsHowAMultipartBodyIsMalformed(string $contentType, string $body, ?string $message): void
    {
        $contract = $this->bodyContract(['multipart/form-data' => ['schema' => [
            'type' => 'object',
            'properties' => ['note' => ['type' => 'string']],
        ]]]);

        $result = $contract->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => $contentType], $body));

        Assert::same($result->violations[0]->message ?? null, $message);
    }

    /** @return iterable<string, array{string, string, string|null}> */
    public static function multipartFramingProvider(): iterable
    {
        $note = static fn(string $boundary): string => sprintf(
            "--%s\r\nContent-Disposition: form-data; name=\"note\"\r\n\r\nhi\r\n--%s--\r\n",
            $boundary,
            $boundary,
        );
        $limit = str_repeat('a', 70);

        yield 'no boundary declared' => ['multipart/form-data', $note('X'), 'Multipart request body has no boundary'];
        yield 'boundary at the length limit' => ['multipart/form-data; boundary=' . $limit, $note($limit), null];
        yield 'boundary over the length limit' => ['multipart/form-data; boundary=' . $limit . 'a', $note($limit . 'a'), 'Multipart request boundary is invalid'];
        yield 'boundary with a character RFC 2046 does not allow' => ['multipart/form-data; boundary=a[b', $note('a[b'), 'Multipart request boundary is invalid'];
        yield 'quoted boundary carrying a space' => ['multipart/form-data; boundary="a b"', $note('a b'), null];
        yield 'body that does not open with the boundary' => ['multipart/form-data; boundary=X', $note('Y'), 'Multipart request body has invalid boundary framing'];
        yield 'part that does not follow its delimiter with CRLF' => [
            'multipart/form-data; boundary=X',
            "--X\r\nContent-Disposition: form-data; name=\"note\"\r\n\r\nhi\r\n--XContent-Disposition: form-data; name=\"other\"\r\n\r\nyo\r\n--X--\r\n",
            'Multipart request part has invalid framing',
        ];
        yield 'part without a header terminator' => [
            'multipart/form-data; boundary=X',
            "--X\r\nContent-Disposition: form-data; name=\"note\"\r\n--X--\r\n",
            'Multipart request part has no header terminator',
        ];
        yield 'part header block over the byte budget' => [
            'multipart/form-data; boundary=X',
            sprintf(
                "--X\r\nContent-Disposition: form-data; name=\"note\"\r\nX-Pad: %s\r\n\r\nhi\r\n--X--\r\n",
                str_repeat('p', 16 * 1024),
            ),
            'Multipart request part headers exceed 16384 bytes',
        ];
        yield 'part with a header line that is not a header' => [
            'multipart/form-data; boundary=X',
            "--X\r\nContent-Disposition: form-data; name=\"note\"\r\nnot a header\r\n\r\nhi\r\n--X--\r\n",
            'Multipart request part contains an invalid header',
        ];
        yield 'part that repeats a header' => [
            'multipart/form-data; boundary=X',
            "--X\r\nContent-Disposition: form-data; name=\"note\"\r\nX-A: 1\r\nx-a: 2\r\n\r\nhi\r\n--X--\r\n",
            'Multipart request part repeats header "x-a"',
        ];
        yield 'part without a form-data disposition' => [
            'multipart/form-data; boundary=X',
            "--X\r\nContent-Disposition: attachment; name=\"note\"\r\n\r\nhi\r\n--X--\r\n",
            'Multipart request part requires Content-Disposition form-data with a name',
        ];
        yield 'part with no name at all' => [
            'multipart/form-data; boundary=X',
            "--X\r\nContent-Disposition: form-data; filename=\"f\"\r\n\r\nhi\r\n--X--\r\n",
            'Multipart request part requires Content-Disposition form-data with a name',
        ];
        yield 'scalar property sent twice' => [
            'multipart/form-data; boundary=X',
            "--X\r\nContent-Disposition: form-data; name=\"note\"\r\n\r\nhi\r\n--X\r\nContent-Disposition: form-data; name=\"note\"\r\n\r\nyo\r\n--X--\r\n",
            'Multipart property "note" occurs more than once',
        ];
        yield 'more parts than the budget allows' => [
            'multipart/form-data; boundary=X',
            '--X' . str_repeat("\r\nContent-Disposition: form-data; name=\"note\"\r\n\r\nhi\r\n--X", 129) . "--\r\n",
            'Multipart request body exceeds 128 parts',
        ];
    }

    /**
     * A multipart `encoding` declaration this package cannot read fails
     * closed, and a header declaration is read as a `simple` request header
     * parameter is. Both are the document talking about the part, so the
     * violation names the property and the header rather than the payload.
     */
    #[DataProvider('multipartEncodingProvider')]
    public function readsAMultipartEncodingDeclarationOrFailsClosed(array $encoding, string $body, ?string $message): void
    {
        $contract = $this->bodyContract(['multipart/form-data' => [
            'schema' => ['type' => 'object', 'properties' => ['note' => ['type' => 'string']]],
            'encoding' => $encoding,
        ]]);

        $result = $contract->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'multipart/form-data; boundary=X'], $body));

        Assert::same($result->violations[0]->message ?? null, $message);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string|null}> */
    public static function multipartEncodingProvider(): iterable
    {
        $part = static fn(string $headers): string => sprintf(
            "--X\r\nContent-Disposition: form-data; name=\"note\"%s\r\n\r\nhi\r\n--X--\r\n",
            $headers,
        );
        $plain = $part('');

        yield 'an unsupported encoding style fails closed' => [
            ['note' => ['style' => 'simple']],
            $plain,
            'Encoding style for multipart property "note" must be form',
        ];
        yield 'an empty contentType fails closed' => [
            ['note' => ['contentType' => '']],
            $plain,
            'Encoding contentType for multipart property "note" must be a non-empty string',
        ];
        yield 'a non-string contentType fails closed' => [
            ['note' => ['contentType' => 7]],
            $plain,
            'Encoding contentType for multipart property "note" must be a non-empty string',
        ];
        yield 'a non-boolean explode fails closed' => [
            ['note' => ['explode' => 'yes']],
            $plain,
            'Encoding explode for multipart property "note" must be a boolean',
        ];
        yield 'a header declared with an unsupported style fails closed' => [
            ['note' => ['headers' => ['X-Trace' => ['style' => 'form', 'schema' => ['type' => 'string']]]]],
            $part("\r\nX-Trace: abc"),
            'Multipart property "note" header "X-Trace" uses an unsupported style',
        ];
        yield 'a header that does not satisfy its schema fails closed' => [
            ['note' => ['headers' => ['X-Trace' => ['schema' => ['type' => 'integer']]]]],
            $part("\r\nX-Trace: abc"),
            'Multipart property "note" header "X-Trace" does not match its schema',
        ];
        yield 'a header exploded as declared is read that way' => [
            ['note' => ['headers' => ['X-Trace' => ['explode' => true, 'schema' => [
                'type' => 'object',
                'properties' => ['a' => ['type' => 'integer']],
                'required' => ['a'],
            ]]]]],
            $part("\r\nX-Trace: a=1"),
            null,
        ];
        yield 'a declared header is otherwise just read' => [
            ['note' => ['headers' => ['X-Trace' => ['schema' => ['type' => 'integer']]]]],
            $part("\r\nX-Trace: 7"),
            null,
        ];
    }

    /**
     * An array property carries one part per item, and a JSON part carries a
     * value that is not a string at all. Each shape the item can take is
     * passed through as itself; anything else fails closed rather than
     * reaching the schema as something it is not.
     */
    #[DataProvider('multipartArrayItemProvider')]
    public function passesEveryJsonShapeThroughAnArrayPart(string $items, string $json, bool $valid): void
    {
        $contract = $this->bodyContract(['multipart/form-data' => [
            'schema' => ['type' => 'object', 'required' => ['tags'], 'properties' => [
                'tags' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => $items]],
            ]],
            'encoding' => ['tags' => ['contentType' => 'application/json']],
        ]]);
        $body = sprintf(
            "--X\r\nContent-Disposition: form-data; name=\"tags\"\r\nContent-Type: application/json\r\n\r\n%s\r\n--X--\r\n",
            $json,
        );

        $result = $contract->validateRequest(new ServerRequest('POST', '/b', ['Content-Type' => 'multipart/form-data; boundary=X'], $body));

        Assert::same($result->isValid(), $valid);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function multipartArrayItemProvider(): iterable
    {
        yield 'integer items' => ['integer', '[1, 2]', true];
        yield 'number items' => ['number', '[1.5]', true];
        yield 'boolean items' => ['boolean', '[true, false]', true];
        yield 'null items' => ['null', '[null]', true];
        yield 'object items' => ['object', '[{"a": 1}]', true];
        yield 'array items' => ['array', '[[1], []]', true];
        // A JSON string item is still coerced by its schema, exactly as a
        // text part is; a value that is not a number stays what it was.
        yield 'integer items coercing a numeric string' => ['integer', '["1"]', true];
        yield 'integer items rejecting a non-numeric string' => ['integer', '["x"]', false];
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
            $this->bodyContract(['application/json' => ['schema' => [['type' => 'string']]]]);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI schema of media type "application/json" of requestBody of operation POST /b must be an object');
        }

        try {
            $this->bodyContract(['application/json' => ['schema' => [0 => ['type' => 'string'], 'a' => 1]]]);
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI schema keys of media type "application/json" of requestBody of operation POST /b must be strings');
        }
    }

    /**
     * A schema nested inside a parameter schema is read where the value is
     * decoded, not where the document is compiled, so an unreadable one still
     * has to leave as a contract error rather than as a deserialization
     * violation blamed on the request.
     */
    public function reportsAnUnreadableNestedParameterSchemaAsAContractError(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/n' => ['get' => [
            'parameters' => [['name' => 'ids', 'in' => 'query', 'schema' => ['type' => 'array', 'items' => [['type' => 'string']]]]],
            'responses' => ['204' => []],
        ]]]]);

        try {
            $contract->validateRequest(new ServerRequest('GET', '/n?ids=1'));
            Assert::true(actual: false);
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'Schema must be an object');
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
