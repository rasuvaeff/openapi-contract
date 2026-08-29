<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\ContractViolation;
use Rasuvaeff\OpenApiContract\MatchedOperation;

$contract = Contract::fromArray([
    'openapi' => '3.1.0',
    'paths' => [
        '/pets/{id}' => [
            'get' => [
                'operationId' => 'pets.get',
                'parameters' => [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1]],
                ],
                'responses' => [
                    '200' => ['content' => ['application/json' => ['schema' => [
                        'type' => 'object',
                        'required' => ['id', 'name'],
                        'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']],
                    ]]]],
                    '404' => [],
                ],
            ],
        ],
    ],
]);

echo 'operations: ';
foreach ($contract->operations() as $operation) {
    echo $operation->key, ' (', $operation->method, ' ', $operation->path, ') ';
}
echo PHP_EOL;

$request = new ServerRequest('GET', '/pets/42');
$matched = $contract->requireMatch($request);
echo 'matched: ', $matched instanceof MatchedOperation ? $matched->operation->key : 'none';
echo ' with path parameters ', json_encode($matched->pathParameters), PHP_EOL;

$valid = $contract->validateExchange($request, new Response(200, ['Content-Type' => 'application/json'], '{"id":42,"name":"Milo"}'));
echo 'conforming exchange valid: ', var_export($valid->isValid(), true), PHP_EOL;

$broken = $contract->validateExchange($request, new Response(200, ['Content-Type' => 'application/json'], '{"id":"forty-two"}'));
foreach ($broken->violations as $violation) {
    echo 'violation: [', $violation->code, '] at ', $violation->instancePath, ' — ', $violation->message, PHP_EOL;
    echo '  spec pointer: ', $violation->specPointer, PHP_EOL;
}

try {
    $broken->assertValid();
} catch (ContractViolation $exception) {
    echo 'assertValid: ', $exception->getMessage(), PHP_EOL;
}
