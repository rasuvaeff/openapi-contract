<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests\Support;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Milestone-0 transport spike. The eventual public adapter belongs in
 * property-testing-openapi, not in this package.
 *
 * @internal
 */
final readonly class Psr15TransportFixture
{
    public function __construct(
        private RequestHandlerInterface $handler,
        private ServerRequestFactoryInterface $serverRequestFactory,
    ) {}

    public function send(RequestInterface $request): ResponseInterface
    {
        $serverRequest = $this->serverRequestFactory
            ->createServerRequest(method: $request->getMethod(), uri: $request->getUri());
        foreach ($request->getHeaders() as $name => $values) {
            $serverRequest = $serverRequest->withHeader($name, $values);
        }

        return $this->handler->handle($serverRequest->withBody($request->getBody()));
    }
}
