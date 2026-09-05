<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\ContractViolation;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaDialect;
use Rasuvaeff\OpenApiContract\Internal\Validation\RequestValidator;
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
