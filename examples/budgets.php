<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\InvalidContract;
use Rasuvaeff\OpenApiContract\Limits;

// Budgets are policy, and the policy is the caller's.

$document = [
    'openapi' => '3.1.0',
    'paths' => ['/reports' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => [
        'schema' => ['type' => 'object'],
    ]]]]]]],
];

$request = new ServerRequest('GET', '/reports');
$big = new Response(200, ['Content-Type' => 'application/json'], '{"rows":[' . str_repeat('1,', 700_000) . '1]}');

printf("The response is %.1f MiB.\n\n", strlen((string) $big->getBody()) / 1048576);

// Under the default budget of 1 MiB the body is not read at all. The violation
// says so: it is a refusal to look, not a verdict about the message.
$result = Contract::fromArray($document)->validateExchange($request, $big);
printf("default budget      -> %s\n", $result->violations[0]->code);
printf("                       %s\n\n", $result->violations[0]->message);

// Raise it and the same exchange is judged on its merits.
$raised = Contract::fromArray($document, new Limits(messageBodyBytes: 8 * 1024 * 1024));
printf("messageBodyBytes 8M -> %s\n\n", $raised->validateExchange($request, $big)->isValid() ? 'valid' : 'invalid');

// The document budgets are the other half. `documentNodes` bounds what a
// document expands into rather than what it weighs: YAML anchors make nodes
// out of no bytes at all, so the byte budget alone does not bound the memory a
// document costs.
try {
    Contract::fromArray($document, new Limits(documentNodes: 5));
} catch (InvalidContract $exception) {
    printf("documentNodes 5     -> %s\n", $exception->getMessage());
}

try {
    Contract::fromJson('{"openapi":"3.1.0","paths":{}}', 'tiny.json', new Limits(documentBytes: 8));
} catch (InvalidContract $exception) {
    printf("documentBytes 8     -> %s\n", $exception->getMessage());
}
