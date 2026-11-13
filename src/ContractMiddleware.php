<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

use Closure;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A PSR-15 middleware that validates every incoming request against the
 * contract before the application sees it.
 *
 * Violations are handed to a configurable handler — a closure that receives
 * the violation list and the request and answers either a PSR-7 response
 * (which short-circuits the pipeline) or `null` (which lets the request
 * through). The default handler is a no-op: an observability-only middleware
 * that never changes the outcome, so wiring one is always safe to ship first.
 * `strict()` builds the rejecting variant over a PSR-17 response factory: a
 * request that breaks the contract gets a 400 `application/problem+json` body whose
 * `violations` entries carry the stable violation fields — code, location,
 * instance path and message — and never the expected/actual values, which
 * the formatter redacts per location and a JSON body would not.
 *
 * Response validation is deliberately not here: a PSR-15 middleware cannot
 * reject a response without swallowing the application's answer, so the
 * double-pass belongs to the caller — `validateExchange()` after the handler
 * — or to the CLI fixture runner. Validate what you own on the way in.
 *
 * @api
 */
final readonly class ContractMiddleware implements MiddlewareInterface
{
    private const string STRICT_ERROR = 'request does not match the OpenAPI contract';

    /**
     * @param Closure(list<Violation>, ServerRequestInterface): ?ResponseInterface|null $onViolations
     *        null means observe-only: the violations are recorded by nobody
     *        and the request passes through untouched
     */
    public function __construct(
        private Contract $contract,
        private ?Closure $onViolations = null,
    ) {}

    /**
     * The rejecting variant: a request that breaks the contract never
     * reaches the handler and is answered with 400 and an
     * `application/problem+json` body built from the stable violation fields.
     */
    public static function strict(Contract $contract, ResponseFactoryInterface $responseFactory): self
    {
        return new self(
            $contract,
            static function (array $violations) use ($responseFactory): ResponseInterface {
                $entries = [];
                foreach ($violations as $violation) {
                    $entries[] = [
                        'code' => $violation->code,
                        'operation' => $violation->operation,
                        'location' => $violation->location,
                        'instancePath' => $violation->instancePath,
                        'message' => $violation->message,
                    ];
                }
                $body = json_encode(
                    ['error' => self::STRICT_ERROR, 'violations' => $entries],
                    JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
                );

                $response = $responseFactory->createResponse(400);
                $stream = $response->getBody();
                $stream->write(is_string($body) ? $body : '{"error":"' . self::STRICT_ERROR . '"}');
                if ($stream->isSeekable()) {
                    $stream->rewind();
                }

                $response = $response->withHeader('Content-Type', 'application/problem+json');
                $size = $stream->getSize();

                return $size === null ? $response : $response->withHeader('Content-Length', (string) $size);
            },
        );
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $result = $this->contract->validateRequest($request);
        if ($result->isValid()) {
            return $handler->handle($request);
        }

        $response = $this->onViolations instanceof \Closure
            ? ($this->onViolations)($result->violations, $request)
            : null;

        return $response ?? $handler->handle($request);
    }
}
