<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\OpenApiContract\Tests\Support\Psr15TransportFixture;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;

#[Test]
#[Covers(Psr15TransportFixture::class)]
final class Psr15TransportFeasibilityTest
{
    public function materializesARequestAsServerRequestBeforeHandlingIt(): void
    {
        $handler = Understudy::for(RequestHandlerInterface::class);
        $expectedResponse = new Response(status: 201);
        $request = new Request(
            method: 'POST',
            uri: 'https://api.example.test/pets?limit=2',
            headers: ['Content-Type' => 'application/json'],
            body: Stream::create('{"name":"Pico"}'),
        );
        expect(fn() => $handler->handle(Arg::satisfies(static fn(mixed $received): bool => $received instanceof ServerRequestInterface
            && $received->getMethod() === 'POST'
            && (string) $received->getUri() === 'https://api.example.test/pets?limit=2'
            && $received->getHeaderLine('Content-Type') === 'application/json'
            && (string) $received->getBody() === '{"name":"Pico"}')))->returns($expectedResponse);

        $actualResponse = (new Psr15TransportFixture($handler, new Psr17Factory()))->send($request);

        Assert::same($actualResponse, $expectedResponse);
    }
}
