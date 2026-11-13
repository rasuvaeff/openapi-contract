<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\ContractMiddleware;
use Rasuvaeff\OpenApiContract\ValidationResult;
use Rasuvaeff\OpenApiContract\ValidationResultFormatter;

// Two wirings of the same middleware. The observe-only one records what
// broke and lets it through; the strict one never lets it reach the handler.

$contract = Contract::fromFile(__DIR__ . '/openapi/pets.yaml');
$formatter = new ValidationResultFormatter();

$observing = new ContractMiddleware(
    $contract,
    static function (array $violations, ServerRequestInterface $request) use ($formatter): ?ResponseInterface {
        echo 'observed on ', $request->getMethod(), ' ', $request->getUri(), ":\n",
        $formatter->format(new ValidationResult($violations)), "\n\n";

        return null;
    },
);
$strict = ContractMiddleware::strict($contract, new Psr17Factory());

$handler = new class implements RequestHandlerInterface {
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], '{"id":7,"name":"Rex"}');
    }
};

echo "A request that holds reaches the handler through both wirings:\n";
$observing->process(new ServerRequest('GET', 'https://api.test/pets/7'), $handler);
$strict->process(new ServerRequest('GET', 'https://api.test/pets/7'), $handler);
echo "  -> handler answered\n\n";

echo "A request the contract refuses:\n";
$observing->process(new ServerRequest('GET', 'https://api.test/pets/0'), $handler);
$response = $strict->process(new ServerRequest('GET', 'https://api.test/pets/0'), $handler);
echo '  strict answered ' . $response->getStatusCode() . ' with:', "\n", (string) $response->getBody(), "\n";
