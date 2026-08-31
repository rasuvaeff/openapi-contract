<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests\Support;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * PSR-7 request double whose URI skips the scheme/host lowercasing that
 * compliant implementations perform, so matching must normalize on its own.
 */
final readonly class UnnormalizedRequest implements RequestInterface
{
    public function __construct(
        private string $method,
        private string $scheme,
        private string $host,
        private string $path,
    ) {}

    #[\Override]
    public function getUri(): UriInterface
    {
        $request = $this;

        return new readonly class ($request->scheme, $request->host, $request->path) implements UriInterface {
            public function __construct(
                private string $scheme,
                private string $host,
                private string $path,
            ) {}

            #[\Override]
            public function getScheme(): string
            {
                return $this->scheme;
            }

            #[\Override]
            public function getAuthority(): string
            {
                return $this->host;
            }

            #[\Override]
            public function getUserInfo(): string
            {
                return '';
            }

            #[\Override]
            public function getHost(): string
            {
                return $this->host;
            }

            #[\Override]
            public function getPort(): ?int
            {
                return null;
            }

            #[\Override]
            public function getPath(): string
            {
                return $this->path;
            }

            #[\Override]
            public function getQuery(): string
            {
                return '';
            }

            #[\Override]
            public function getFragment(): string
            {
                return '';
            }

            #[\Override]
            public function withScheme(string $scheme): UriInterface
            {
                throw new \LogicException('Read-only test double');
            }

            #[\Override]
            public function withUserInfo(string $user, ?string $password = null): UriInterface
            {
                throw new \LogicException('Read-only test double');
            }

            #[\Override]
            public function withHost(string $host): UriInterface
            {
                throw new \LogicException('Read-only test double');
            }

            #[\Override]
            public function withPort(?int $port): UriInterface
            {
                throw new \LogicException('Read-only test double');
            }

            #[\Override]
            public function withPath(string $path): UriInterface
            {
                throw new \LogicException('Read-only test double');
            }

            #[\Override]
            public function withQuery(string $query): UriInterface
            {
                throw new \LogicException('Read-only test double');
            }

            #[\Override]
            public function withFragment(string $fragment): UriInterface
            {
                throw new \LogicException('Read-only test double');
            }

            #[\Override]
            public function __toString(): string
            {
                return $this->scheme . '://' . $this->host . $this->path;
            }
        };
    }

    #[\Override]
    public function getMethod(): string
    {
        return $this->method;
    }

    #[\Override]
    public function getRequestTarget(): string
    {
        return $this->path;
    }

    #[\Override]
    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    /** @return array<string, list<string>> */
    #[\Override]
    public function getHeaders(): array
    {
        return [];
    }

    #[\Override]
    public function hasHeader(string $name): bool
    {
        return false;
    }

    /** @return list<string> */
    #[\Override]
    public function getHeader(string $name): array
    {
        return [];
    }

    #[\Override]
    public function getHeaderLine(string $name): string
    {
        return '';
    }

    #[\Override]
    public function getBody(): StreamInterface
    {
        throw new \LogicException('Read-only test double');
    }

    #[\Override]
    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        throw new \LogicException('Read-only test double');
    }

    #[\Override]
    public function withMethod(string $method): RequestInterface
    {
        throw new \LogicException('Read-only test double');
    }

    #[\Override]
    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        throw new \LogicException('Read-only test double');
    }

    #[\Override]
    public function withProtocolVersion(string $version): RequestInterface
    {
        throw new \LogicException('Read-only test double');
    }

    #[\Override]
    public function withHeader(string $name, $value): RequestInterface
    {
        throw new \LogicException('Read-only test double');
    }

    #[\Override]
    public function withAddedHeader(string $name, $value): RequestInterface
    {
        throw new \LogicException('Read-only test double');
    }

    #[\Override]
    public function withoutHeader(string $name): RequestInterface
    {
        throw new \LogicException('Read-only test double');
    }

    #[\Override]
    public function withBody(StreamInterface $body): RequestInterface
    {
        throw new \LogicException('Read-only test double');
    }
}
