<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Internal\Validation\ResponseValidator;
use Rasuvaeff\OpenApiContract\Limits;
use Rasuvaeff\OpenApiContract\Tests\Support\ViolationRow;
use Rasuvaeff\OpenApiContract\ValidationResult;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(ResponseValidator::class)]
final class ResponseViolationDetailsTest
{
    /**
     * @param \Closure(): ValidationResult $run
     * @param list<array{string, string, string, string, mixed, mixed, string}> $expected
     */
    #[DataProvider('scenarioProvider')]
    public function pinsEveryFieldOfTheViolationsAResponseGets(\Closure $run, array $expected): void
    {
        Assert::same(ViolationRow::of($run()), $expected);
    }

    /** @return iterable<string, array{\Closure(): ValidationResult, list<array{string, string, string, string, mixed, mixed, string}>}> */
    public static function scenarioProvider(): iterable
    {
        foreach (self::scenarios() as $name => $run) {
            yield $name => [$run, self::EXPECTED[$name] ?? []];
        }
    }

    /** @return array<string, \Closure(): ValidationResult> */
    public static function scenarios(): array
    {
        $contract = static fn(?Limits $limits = null): Contract => Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/p' => ['get' => ['responses' => [
                '200' => [
                    'headers' => [
                        'Content-Type' => ['required' => true],
                        'X-Req' => ['required' => true, 'schema' => ['type' => 'string']],
                        'X-Opt' => ['required' => false],
                        'X-Num' => ['schema' => ['type' => 'integer']],
                    ],
                    'content' => [
                        'application/json' => ['schema' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]],
                        'text/plain' => ['schema' => ['type' => 'string', 'minLength' => 3]],
                    ],
                ],
                '201' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
                '202' => ['content' => ['application/json' => ['schema' => false]]],
                '203' => ['content' => ['application/xml' => ['schema' => false]]],
                '204' => ['description' => 'none'],
            ]]]],
        ], $limits);
        $exchange = static fn(Response $response, ?Limits $limits = null): ValidationResult => $contract($limits)->validateExchange(new ServerRequest('GET', '/p'), $response);
        $ok = ['X-Req' => 'r', 'Content-Type' => 'application/json'];

        return [
            'a status that is no status' => static fn(): ValidationResult => $exchange(new Response(600)),
            'a status nobody declared' => static fn(): ValidationResult => $exchange(new Response(404)),
            'a required header missing, with a Content-Type header declared before it' => static fn(): ValidationResult => $exchange(new Response(200, ['Content-Type' => 'application/json'], '{"id":1}')),
            'a header that breaks its schema next to a body that breaks its own' => static fn(): ValidationResult => $exchange(new Response(200, $ok + ['X-Num' => 'abc'], '{}')),
            'a body that is not JSON' => static fn(): ValidationResult => $exchange(new Response(200, $ok, '{nope')),
            'a body of a media type nobody declared' => static fn(): ValidationResult => $exchange(new Response(200, ['X-Req' => 'r', 'Content-Type' => 'image/png'], 'x')),
            'a body that breaks its schema' => static fn(): ValidationResult => $exchange(new Response(200, $ok, '{"id":"x"}')),
            'a text body that breaks its schema' => static fn(): ValidationResult => $exchange(new Response(200, ['X-Req' => 'r', 'Content-Type' => 'text/plain'], 'ab')),
            'a missing body' => static fn(): ValidationResult => $exchange(new Response(201, ['Content-Type' => 'application/json'])),
            'a body where the schema admits none' => static fn(): ValidationResult => $exchange(new Response(202, ['Content-Type' => 'application/json'], '{}')),
            'an opaque body where the schema admits none' => static fn(): ValidationResult => $exchange(new Response(203, ['Content-Type' => 'application/xml'], '<a/>')),
            'a body over the budget' => static fn(): ValidationResult => $exchange(new Response(200, $ok, str_repeat(' ', 16)), new Limits(messageBodyBytes: 8)),
            'a body that fills the budget' => static fn(): ValidationResult => $exchange(new Response(200, $ok, '{"id":1}'), new Limits(messageBodyBytes: 8)),
            'a body that makes no progress' => static function () use ($exchange, $ok): ValidationResult {
                $stream = Understudy::for(StreamInterface::class);
                when(static fn(): bool => $stream->isSeekable())->returns(true);
                when(static fn(): int => $stream->tell())->returns(0);
                when(static fn(): bool => $stream->eof())->returns(false);
                when(static fn(): string => $stream->read(8192))->returns('');
                expect(static fn() => $stream->seek(0));

                return $exchange((new Response(200, $ok))->withBody($stream));
            },
            'a body that cannot be rewound' => static function () use ($exchange, $ok): ValidationResult {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                if ($pair === false) {
                    throw new \RuntimeException('Unable to create a socket pair');
                }
                fwrite($pair[0], '{}');
                fclose($pair[0]);

                return $exchange((new Response(200, $ok))->withBody(Stream::create($pair[1])));
            },
        ];
    }

    private const array EXPECTED = [
        'a status that is no status' => [
            ['response.status.invalid', 'status', '$', '/paths/~1p/get/responses', 'HTTP status between 100 and 599', 600, 'Response status 600 is not a valid HTTP status code'],
        ],
        'a status nobody declared' => [
            ['response.status.mismatch', 'status', '$', '/paths/~1p/get/responses', [200, 201, 202, 203, 204], 404, 'Response status 404 is not declared'],
        ],
        'a required header missing, with a Content-Type header declared before it' => [
            ['response.header.missing', 'header', 'X-Req', '/paths/~1p/get/responses/200/headers/X-Req', 'required response header', null, 'Required response header "X-Req" is missing'],
        ],
        'a header that breaks its schema next to a body that breaks its own' => [
            ['response.header.schema', 'header', 'X-Num', '/paths/~1p/get/responses/200/headers/X-Num/schema', ['type' => 'integer'], 'abc', 'Response header "X-Num" does not match its schema'],
            ['response.body.schema', 'body', '$.id', '/paths/~1p/get/responses/200/content/application~1json/schema', ['required' => ['id']], null, 'Response body member "$.id" is required and absent'],
        ],
        'a body that is not JSON' => [
            ['response.body.json', 'body', '$', '/paths/~1p/get/responses/200/content/application~1json', 'valid JSON', '{nope', 'Response body is not valid JSON'],
        ],
        'a body of a media type nobody declared' => [
            ['response.body.media_type', 'body', '$', '/paths/~1p/get/responses/200/content', ['application/json', 'text/plain'], 'image/png', 'Response media type "image/png" is not declared'],
        ],
        'a body that breaks its schema' => [
            ['response.body.schema', 'body', '$.id', '/paths/~1p/get/responses/200/content/application~1json/schema', ['type' => 'integer'], 'x', 'Response body member "$.id" does not satisfy "type"'],
        ],
        'a text body that breaks its schema' => [
            ['response.body.schema', 'body', '$', '/paths/~1p/get/responses/200/content/text~1plain/schema', ['type' => 'string', 'minLength' => 3], 'ab', 'Response body does not match its schema'],
        ],
        'a missing body' => [
            ['response.body.missing', 'body', '$', '/paths/~1p/get/responses/201/content', ['application/json'], null, 'Declared response body is missing'],
        ],
        'a body where the schema admits none' => [
            ['response.body.schema', 'body', '$', '/paths/~1p/get/responses/202/content/application~1json/schema', null, [], 'Response body does not match its schema'],
        ],
        'an opaque body where the schema admits none' => [
            ['response.body.schema', 'body', '$', '/paths/~1p/get/responses/203/content/application~1xml/schema', false, '<a/>', 'Response body does not match its schema'],
        ],
        'a body over the budget' => [
            ['response.body.too_large', 'body', '$', '/paths/~1p/get/responses/200/content', 'body up to 8 bytes', 'body exceeds validation byte budget', 'Response body exceeds 8 bytes'],
        ],
        'a body that fills the budget' => [],
        'a body that makes no progress' => [
            ['response.body.unreadable', 'body', '$', '/paths/~1p/get/responses/200/content', 'readable body stream', 'stream that made no progress', 'Response body stream could not be read'],
        ],
        'a body that cannot be rewound' => [
            ['response.body.non_seekable', 'body', '$', '/paths/~1p/get/responses/200/content', 'seekable body stream', 'non-seekable body stream', 'Response body stream must be seekable for validation'],
        ],
    ];
}
