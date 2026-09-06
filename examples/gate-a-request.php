<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\ValidationResult;
use Rasuvaeff\OpenApiContract\ValidationResultFormatter;

// The shape a PSR-15 middleware takes: validate the request, hand it on, then
// validate what came back. Written as a plain callable so the example runs
// without a middleware dispatcher.

$contract = Contract::fromFile(__DIR__ . '/openapi/pets.yaml');
$formatter = new ValidationResultFormatter();

$gate = static function (ServerRequestInterface $request, callable $handler) use ($contract, $formatter): ResponseInterface {
    $incoming = $contract->validateRequest($request);
    if (!$incoming->isValid()) {
        // 400 is the caller's fault. Note what the codes mean before turning
        // this into a hard gate: `request.body.too_large` says the validator
        // declined to read the body, not that the request was wrong.
        echo $formatter->format($incoming), "\n\n";

        return new Response(400, ['Content-Type' => 'application/json'], '{"error":"request does not match the contract"}');
    }

    $response = $handler($request);
    $outgoing = $contract->validateExchange($request, $response);
    if (!$outgoing->isValid()) {
        // A response that breaks the contract is the service's own fault:
        // report it, do not blame the caller.
        echo $formatter->format($outgoing), "\n\n";
    }

    return $response;
};

$handler = static fn(ServerRequestInterface $request): ResponseInterface => new Response(
    200,
    ['Content-Type' => 'application/json'],
    '{"id":7,"name":"Rex"}',
);

echo "A request and a response that both hold:\n";
$gate(new ServerRequest('GET', 'https://api.test/pets/7'), $handler);
echo "  -> passed\n\n";

echo "A request the contract refuses:\n";
$gate(new ServerRequest('GET', 'https://api.test/pets/0'), $handler);

echo "A response the service got wrong:\n";
$gate(
    new ServerRequest('GET', 'https://api.test/pets/7'),
    static fn(): ResponseInterface => new Response(200, ['Content-Type' => 'application/json'], '{"id":"seven"}'),
);

assert($contract->validateRequest(new ServerRequest('GET', 'https://api.test/pets/7')) instanceof ValidationResult);
