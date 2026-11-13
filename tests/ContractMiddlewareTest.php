<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Closure;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\ContractMiddleware;
use Rasuvaeff\OpenApiContract\Tests\Support\UnknownSizeStream;
use Rasuvaeff\OpenApiContract\Violation;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ContractMiddleware::class)]
#[Covers(Contract::class)]
#[Covers(Violation::class)]
final class ContractMiddlewareTest
{
    public function passesAValidRequestToTheHandler(): void
    {
        $handled = false;
        $response = $this->middleware()->process(
            new ServerRequest('GET', 'https://api.test/pets/7'),
            $this->handler(static function () use (&$handled): ResponseInterface {
                $handled = true;

                return new Response(204);
            }),
        );

        Assert::true($handled);
        Assert::same($response->getStatusCode(), 204);
    }

    public function defaultHandlerObservesWithoutBlocking(): void
    {
        $handled = false;
        $response = $this->middleware()->process(
            new ServerRequest('GET', 'https://api.test/pets/0'),
            $this->handler(static function () use (&$handled): ResponseInterface {
                $handled = true;

                return new Response(204);
            }),
        );

        // A no-op handler is an observability-only middleware: the violating
        // request still reaches the application, whatever it decides to do.
        Assert::true($handled);
        Assert::same($response->getStatusCode(), 204);
    }

    public function customHandlerSeesTheViolationsAndTheRequest(): void
    {
        $seen = [];
        $response = $this->middleware(
            onViolations: static function (array $violations, ServerRequestInterface $request) use (&$seen): ?ResponseInterface {
                $seen = [$violations, $request];

                return null;
            },
        )->process(
            new ServerRequest('GET', 'https://api.test/pets/0'),
            $this->handler(static fn(): ResponseInterface => new Response(204)),
        );

        /** @var list<Violation> $violations */
        $violations = $seen[0];
        Assert::false($violations === []);
        Assert::same($violations[0]->code, 'request.parameter.schema');
        Assert::instanceOf($seen[1], ServerRequestInterface::class);
        // null from the handler lets the request through.
        Assert::same($response->getStatusCode(), 204);
    }

    public function customHandlerShortCircuitsWithItsOwnResponse(): void
    {
        $handled = false;
        $response = $this->middleware(
            onViolations: static fn(): \Psr\Http\Message\ResponseInterface => new Response(418),
        )->process(
            new ServerRequest('GET', 'https://api.test/pets/0'),
            $this->handler(static function () use (&$handled): ResponseInterface {
                $handled = true;

                return new Response(204);
            }),
        );

        Assert::false($handled);
        Assert::same($response->getStatusCode(), 418);
    }

    public function strictModeRejectsWithAJsonProblemBody(): void
    {
        $handled = false;
        $response = ContractMiddleware::strict($this->contract(), new Psr17Factory())->process(
            new ServerRequest('GET', 'https://api.test/pets/0'),
            $this->handler(static function () use (&$handled): ResponseInterface {
                $handled = true;

                return new Response(204);
            }),
        );

        Assert::false($handled);
        Assert::same($response->getStatusCode(), 400);
        Assert::same($response->getHeaderLine('Content-Type'), 'application/problem+json');
        /** @var array{error: string, violations: list<array{code: string, location: string, instancePath: string, message: string}>} $body */
        $body = json_decode((string) $response->getBody(), associative: true);
        Assert::same($body['error'], 'request does not match the OpenAPI contract');
        Assert::false($body['violations'] === []);
        Assert::same($body['violations'][0]['code'], 'request.parameter.schema');
        Assert::false(array_key_exists('expected', $body['violations'][0]));
        Assert::false(array_key_exists('actual', $body['violations'][0]));
        Assert::same(strlen((string) $response->getBody()), (int) $response->getHeaderLine('Content-Length'));
    }

    public function strictModeBodyIsReadableWithoutRewinding(): void
    {
        $response = ContractMiddleware::strict($this->contract(), new Psr17Factory())->process(
            new ServerRequest('GET', 'https://api.test/pets/0'),
            $this->handler(static fn(): ResponseInterface => new Response(204)),
        );

        // The stream cursor sits at the start after the write, so a consumer
        // reading with getContents() — not the rewinding (string) cast — sees
        // the violation summary, not an empty body.
        $contents = $response->getBody()->getContents();
        Assert::false($contents === '');

        /** @var array{error: string} $body */
        $body = json_decode($contents, associative: true);
        Assert::same($body['error'], 'request does not match the OpenAPI contract');
    }

    public function strictModeOmitsContentLengthWhenSizeIsUnknown(): void
    {
        $factory = new readonly class implements ResponseFactoryInterface {
            #[\Override]
            public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
            {
                return new Response(400, body: new UnknownSizeStream(\Nyholm\Psr7\Stream::create(fopen('php://temp', 'r+b'))));
            }
        };

        $response = ContractMiddleware::strict($this->contract(), $factory)->process(
            new ServerRequest('GET', 'https://api.test/pets/0'),
            $this->handler(static fn(): ResponseInterface => new Response(204)),
        );

        Assert::same($response->getStatusCode(), 400);
        Assert::same($response->getHeaderLine('Content-Length'), '');
        Assert::false((string) $response->getBody() === '');
    }

    public function strictModePassesAValidRequestThrough(): void
    {
        $response = ContractMiddleware::strict($this->contract(), new Psr17Factory())->process(
            new ServerRequest('GET', 'https://api.test/pets/7'),
            $this->handler(static fn(): ResponseInterface => new Response(200, ['Content-Type' => 'application/json'], '{"id":7,"name":"Rex"}')),
        );

        Assert::same($response->getStatusCode(), 200);
    }

    private function middleware(?callable $onViolations = null): ContractMiddleware
    {
        return new ContractMiddleware(
            contract: $this->contract(),
            onViolations: $onViolations === null ? null : Closure::fromCallable($onViolations),
        );
    }

    private function contract(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'servers' => [['url' => 'https://api.test']],
            'paths' => [
                '/pets/{id}' => [
                    'get' => [
                        'operationId' => 'pets.get',
                        'parameters' => [
                            [
                                'name' => 'id',
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'integer', 'minimum' => 1],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'one pet',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'required' => ['id'],
                                            'properties' => ['id' => ['type' => 'integer']],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function handler(callable $handle): RequestHandlerInterface
    {
        return new readonly class ($handle) implements RequestHandlerInterface {
            /** @param Closure(ServerRequestInterface): ResponseInterface $handle */
            public function __construct(
                private Closure $handle,
            ) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->handle)($request);
            }
        };
    }
}
