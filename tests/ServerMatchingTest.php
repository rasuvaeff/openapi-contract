<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Nyholm\Psr7\Request;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Internal\Compilation\DocumentCompiler;
use Rasuvaeff\OpenApiContract\InvalidContract;
use Rasuvaeff\OpenApiContract\MatchedOperation;
use Rasuvaeff\OpenApiContract\Tests\Support\UnnormalizedRequest;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Contract::class)]
#[Covers(DocumentCompiler::class)]
final class ServerMatchingTest
{
    public function selectsTheOperationOfTheMatchingHost(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => [
            '/v1/x' => ['get' => [
                'operationId' => 'onA',
                'servers' => [['url' => 'https://a.example.com']],
                'responses' => ['200' => ['description' => 'ok']],
            ]],
            '/x' => ['get' => [
                'operationId' => 'onB',
                'servers' => [['url' => 'https://b.example.com/v1']],
                'responses' => ['200' => ['description' => 'ok']],
            ]],
        ]]);

        $onA = $contract->match(new Request('GET', 'https://a.example.com/v1/x'));
        Assert::true($onA instanceof MatchedOperation && $onA->operation->key === 'onA');

        $onB = $contract->match(new Request('GET', 'https://b.example.com/v1/x'));
        Assert::true($onB instanceof MatchedOperation && $onB->operation->key === 'onB');

        Assert::null($contract->match(new Request('GET', 'https://c.example.com/v1/x')));
    }

    public function keepsPathOnlyRequestsHostAgnostic(): void
    {
        $contract = $this->singleServerContract('https://api.example.com/v1');

        $matched = $contract->match(new Request('GET', '/v1/x'));
        Assert::true($matched instanceof MatchedOperation && $matched->operation->path === '/x');
    }

    public function comparesNormalizedSchemeHostAndEffectivePort(): void
    {
        $contract = $this->singleServerContract('https://API.Example.COM/v1');

        Assert::true($contract->match(new Request('GET', 'https://api.example.com/v1/x')) instanceof MatchedOperation);
        Assert::true($contract->match(new Request('GET', 'https://api.example.com:443/v1/x')) instanceof MatchedOperation);
        Assert::null($contract->match(new Request('GET', 'http://api.example.com/v1/x')));
        Assert::null($contract->match(new Request('GET', 'https://api.example.com:8443/v1/x')));

        $explicit = $this->singleServerContract('https://api.example.com:8443/v1');
        Assert::true($explicit->match(new Request('GET', 'https://api.example.com:8443/v1/x')) instanceof MatchedOperation);
        Assert::null($explicit->match(new Request('GET', 'https://api.example.com/v1/x')));
    }

    public function normalizesUriComponentsOfNonCompliantPsr7Implementations(): void
    {
        $contract = $this->singleServerContract('https://api.example.com/v1');

        $request = new UnnormalizedRequest('GET', 'HTTPS', 'API.EXAMPLE.COM', '/v1/x');
        Assert::true($contract->match($request) instanceof MatchedOperation);
    }

    public function triesEveryServerOfOneOperation(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'servers' => [
            ['url' => 'https://wrong.example.com/v1'],
            ['url' => 'https://right.example.com/v1'],
        ], 'paths' => ['/x' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]]]);

        Assert::true($contract->match(new Request('GET', 'https://right.example.com/v1/x')) instanceof MatchedOperation);
    }

    public function rejectsASchemeMismatchEvenWhenExplicitPortsAgree(): void
    {
        $contract = $this->singleServerContract('https://api.example.com:8080/v1');

        Assert::null($contract->match(new Request('GET', 'http://api.example.com:8080/v1/x')));
    }

    public function treatsDeclaredDefaultPortsAsEquivalentToOmittedOnes(): void
    {
        $http = $this->singleServerContract('http://api.example.com:80/v1');
        Assert::true($http->match(new Request('GET', 'http://api.example.com/v1/x')) instanceof MatchedOperation);

        $https = $this->singleServerContract('https://api.example.com:443/v1');
        Assert::true($https->match(new Request('GET', 'https://api.example.com/v1/x')) instanceof MatchedOperation);

        $custom = $this->singleServerContract('http://api.example.com:81/v1');
        Assert::null($custom->match(new Request('GET', 'http://api.example.com/v1/x')));
    }

    public function acceptsSchemeLikeSegmentsInsideRelativeBases(): void
    {
        $contract = $this->singleServerContract('/proxy/https://inner');

        Assert::same($contract->operations()[0]->serverBases, ['/proxy/https://inner']);
    }

    public function compilesUppercaseServerUrlComponentsToLowercase(): void
    {
        $contract = $this->singleServerContract('HTTPS://API.Example.COM/v1');

        Assert::same($contract->operations()[0]->servers, [
            ['scheme' => 'https', 'host' => 'api.example.com', 'port' => null, 'base' => '/v1'],
        ]);
    }

    public function keepsRelativeServersHostAgnostic(): void
    {
        $contract = $this->singleServerContract('/v1');

        Assert::true($contract->match(new Request('GET', 'https://anything.example.com/v1/x')) instanceof MatchedOperation);
        Assert::true($contract->match(new Request('GET', '/v1/x')) instanceof MatchedOperation);
        Assert::same($contract->operations()[0]->servers, [
            ['scheme' => null, 'host' => null, 'port' => null, 'base' => '/v1'],
        ]);
    }

    public function substitutesServerVariableDefaults(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'servers' => [[
            'url' => 'https://{env}.api.test/{base}',
            'variables' => [
                'env' => ['default' => 'prod', 'enum' => ['prod', 'staging']],
                'base' => ['default' => 'v2'],
            ],
        ]], 'paths' => ['/x' => ['get' => ['operationId' => 'x.get', 'responses' => ['200' => ['description' => 'ok']]]]]]);

        Assert::true($contract->match(new Request('GET', 'https://prod.api.test/v2/x')) instanceof MatchedOperation);
        Assert::null($contract->match(new Request('GET', 'https://staging.api.test/v2/x')));
        Assert::same($contract->operation('x.get')->serverBases, ['/v2']);
        Assert::same($contract->operation('x.get')->servers, [
            ['scheme' => 'https', 'host' => 'prod.api.test', 'port' => null, 'base' => '/v2'],
        ]);
    }

    public function appliesOperationOverPathOverRootServerPrecedence(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'servers' => [['url' => 'https://root.test']],
            'paths' => [
                '/a' => [
                    'servers' => [['url' => 'https://path.test']],
                    'get' => [
                        'operationId' => 'a.get',
                        'servers' => [['url' => 'https://operation.test']],
                        'responses' => ['200' => ['description' => 'ok']],
                    ],
                ],
                '/b' => [
                    'servers' => [['url' => 'https://path.test']],
                    'get' => ['operationId' => 'b.get', 'responses' => ['200' => ['description' => 'ok']]],
                ],
                '/c' => ['get' => ['operationId' => 'c.get', 'responses' => ['200' => ['description' => 'ok']]]],
            ],
        ]);

        Assert::true($contract->match(new Request('GET', 'https://operation.test/a')) instanceof MatchedOperation);
        Assert::null($contract->match(new Request('GET', 'https://root.test/a')));
        Assert::true($contract->match(new Request('GET', 'https://path.test/b')) instanceof MatchedOperation);
        Assert::true($contract->match(new Request('GET', 'https://root.test/c')) instanceof MatchedOperation);
        Assert::null($contract->match(new Request('GET', 'https://path.test/c')));
    }

    public function distinguishesServerMismatchFromUnknownOperation(): void
    {
        $contract = $this->singleServerContract('https://api.example.com/v1');

        $mismatch = $contract->validateRequest(new Request('GET', 'https://evil.example.com/v1/x'));
        Assert::false($mismatch->isValid());
        Assert::same($mismatch->violations[0]->code, 'request.server.mismatch');
        Assert::same($mismatch->violations[0]->specPointer, '/servers');
        Assert::same($mismatch->violations[0]->actual, 'https://evil.example.com');
        Assert::same(
            $mismatch->violations[0]->message,
            'Path /v1/x is declared, but no server of its operations matches https://evil.example.com',
        );

        $unknown = $contract->validateRequest(new Request('GET', 'https://api.example.com/v1/none'));
        Assert::false($unknown->isValid());
        Assert::same($unknown->violations[0]->code, 'request.operation.unknown');
    }

    #[DataProvider('invalidServerProvider')]
    public function failsClosedOnUnsupportedServerDeclarations(array $server, string $messagePart): void
    {
        try {
            Contract::fromArray(['openapi' => '3.1.0', 'servers' => [$server], 'paths' => [
                '/x' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]],
            ]]);
            Assert::true(actual: false, message: 'Expected an invalid server declaration exception');
        } catch (InvalidContract $exception) {
            Assert::string($exception->getMessage())->contains($messagePart);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidServerProvider(): iterable
    {
        yield 'empty url' => [['url' => ''], 'must contain a URL'];
        yield 'bare relative path' => [['url' => 'v2'], 'unsupported form'];
        yield 'protocol-relative url' => [['url' => '//h/x'], 'unsupported form'];
        yield 'query in url' => [['url' => '/x?q=1'], 'unsupported form'];
        yield 'fragment in url' => [['url' => '/x#frag'], 'unsupported form'];
        yield 'userinfo in url' => [['url' => 'https://user:pass@h/x'], 'unsupported form'];
        yield 'passwordless userinfo in url' => [['url' => 'https://user@h/x'], 'unsupported form'];
        yield 'query in absolute url' => [['url' => 'https://h/x?q=1'], 'unsupported form'];
        yield 'fragment in absolute url' => [['url' => 'https://h/x#frag'], 'unsupported form'];
        yield 'unsupported scheme' => [['url' => 'ftp://h/x'], 'unsupported scheme "ftp"'];
        yield 'hostless absolute url' => [['url' => 'https:///x'], 'unsupported form'];
        yield 'undeclared variable' => [['url' => 'https://{env}.h/x'], 'uses variables but declares no variables object'];
        yield 'missing variable entry' => [
            ['url' => 'https://{env}.h/x', 'variables' => ['other' => ['default' => 'a']]],
            'uses undeclared variable "env"',
        ];
        yield 'non-string default' => [
            ['url' => 'https://{env}.h/x', 'variables' => ['env' => ['default' => 5]]],
            'must declare a string default',
        ];
        yield 'missing default' => [
            ['url' => 'https://{env}.h/x', 'variables' => ['env' => ['enum' => ['a']]]],
            'must declare a string default',
        ];
        yield 'empty enum' => [
            ['url' => 'https://{env}.h/x', 'variables' => ['env' => ['default' => 'a', 'enum' => []]]],
            'enum must be a non-empty list',
        ];
        yield 'default outside enum' => [
            ['url' => 'https://{env}.h/x', 'variables' => ['env' => ['default' => 'c', 'enum' => ['a', 'b']]]],
            'default must be one of its enum values',
        ];
    }

    public function keepsTheServerBasesProjectionShape(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'servers' => [
            ['url' => 'https://api.example.com/v1/'],
            ['url' => '/'],
            ['url' => 'https://api.example.com'],
        ], 'paths' => ['/x' => ['get' => ['operationId' => 'x.get', 'responses' => ['200' => ['description' => 'ok']]]]]]);

        Assert::same($contract->operation('x.get')->serverBases, ['/v1', '/', '/']);
    }

    private function singleServerContract(string $url): Contract
    {
        return Contract::fromArray(['openapi' => '3.1.0', 'servers' => [['url' => $url]], 'paths' => [
            '/x' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]],
        ]]);
    }
}
