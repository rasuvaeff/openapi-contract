<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\ValidationResultFormatter;

// The receiving side of a webhook: the document describes what the sender
// delivers under `webhooks`, and the receiver validates the delivery it got
// against the entry it knows it is handling. Nothing is matched by URI —
// the delivery's URL is the receiver's own.
$contract = Contract::fromArray([
    'openapi' => '3.1.0',
    'webhooks' => [
        'payment.completed' => [
            'post' => [
                'operationId' => 'payment.completed',
                'parameters' => [
                    ['name' => 'X-Signature', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
                ],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'oneOf' => [
                        ['$ref' => '#/components/schemas/Card'],
                        ['$ref' => '#/components/schemas/Transfer'],
                    ],
                    'discriminator' => ['propertyName' => 'method', 'mapping' => ['card' => 'Card', 'transfer' => 'Transfer']],
                ]]]],
                'responses' => ['204' => []],
            ],
        ],
    ],
    'components' => ['schemas' => [
        'Card' => ['type' => 'object', 'required' => ['method', 'last4'], 'properties' => [
            'method' => ['type' => 'string'],
            'last4' => ['type' => 'string', 'pattern' => '^[0-9]{4}$'],
        ]],
        'Transfer' => ['type' => 'object', 'required' => ['method', 'iban'], 'properties' => [
            'method' => ['type' => 'string'],
            'iban' => ['type' => 'string'],
        ]],
    ]],
]);

echo 'path operations: ', count($contract->operations()), ', webhooks: ', implode(', ', array_keys($contract->webhooks())), PHP_EOL;

$headers = ['Content-Type' => 'application/json', 'X-Signature' => 'sha256=…'];
$delivery = new ServerRequest('POST', 'https://receiver.example/hooks/payments', $headers, '{"method":"card","last4":"4242"}');
echo 'conforming delivery valid: ', var_export($contract->validateWebhook('payment.completed', $delivery)->isValid(), true), PHP_EOL;

// The body names its branch, and the diagnostics follow it: the failing
// member of the Card schema, not the errors of every branch.
$broken = new ServerRequest('POST', 'https://receiver.example/hooks/payments', $headers, '{"method":"card","last4":"42"}');
echo (new ValidationResultFormatter())->format($contract->validateWebhook('payment.completed', $broken)), PHP_EOL;

// A value that names no branch is reported as exactly that.
$unknown = new ServerRequest('POST', 'https://receiver.example/hooks/payments', $headers, '{"method":"cash"}');
foreach ($contract->validateWebhook('payment.completed', $unknown)->violations as $violation) {
    echo 'violation: [', $violation->code, '] ', $violation->instancePath, ' ', $violation->keyword, ' — ', $violation->message, PHP_EOL;
}

// With the receiver's answer, the response is validated too — the
// exchange, as validateExchange() would judge it for a path operation.
$exchange = $contract->validateWebhook('payment.completed', $delivery, new Response(200));
foreach ($exchange->violations as $violation) {
    echo 'violation: [', $violation->code, '] ', $violation->message, ' (', $violation->specPointer, ')', PHP_EOL;
}

// A name the document does not declare is one structured violation.
foreach ($contract->validateWebhook('payment.refunded', $delivery)->violations as $violation) {
    echo 'violation: [', $violation->code, '] ', $violation->message, PHP_EOL;
}
