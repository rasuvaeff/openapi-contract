<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Internal\Cli\ValidateCommand;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ValidateCommand::class)]
final class ValidateCommandTest
{
    /** @var list<string> */
    private array $lines = [];

    public function passesWhenEveryFixtureHolds(): void
    {
        $dir = $this->fixtures([
            'a-valid-exchange.json' => [
                'request' => ['method' => 'GET', 'uri' => 'https://api.test/pets/7'],
                'response' => ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"id":7}'],
            ],
            'b-valid-request-only.json' => [
                'request' => ['method' => 'GET', 'uri' => 'https://api.test/pets/9'],
            ],
        ]);

        $exit = $this->command()->run([$this->contractFile(), $dir]);

        Assert::same($exit, ValidateCommand::EXIT_OK);
        Assert::same($this->lines, [
            'PASS  a-valid-exchange.json',
            'PASS  b-valid-request-only.json',
            '2 passed, 0 failed',
        ]);
    }

    public function failsOnAContractViolation(): void
    {
        $dir = $this->fixtures([
            'bad-path-parameter.json' => [
                'request' => ['method' => 'GET', 'uri' => 'https://api.test/pets/0'],
            ],
        ]);

        $exit = $this->command()->run([$this->contractFile(), $dir]);

        Assert::same($exit, ValidateCommand::EXIT_VIOLATIONS);
        Assert::same($this->lines[0], 'FAIL  bad-path-parameter.json');
        Assert::true(str_contains(implode("\n", $this->lines), 'code: "request.parameter.schema"'));
        Assert::same($this->lines[count($this->lines) - 1], '0 passed, 1 failed');
    }

    public function failsOnAResponseViolation(): void
    {
        $dir = $this->fixtures([
            'bad-response-body.json' => [
                'request' => ['method' => 'GET', 'uri' => 'https://api.test/pets/7'],
                'response' => ['status' => 200, 'body' => '{"id":"seven"}'],
            ],
        ]);

        $exit = $this->command()->run([$this->contractFile(), $dir]);

        Assert::same($exit, ValidateCommand::EXIT_VIOLATIONS);
        Assert::same($this->lines[0], 'FAIL  bad-response-body.json');
        Assert::true(str_contains(implode("\n", $this->lines), 'exchange: OpenAPI contract validation failed'));
    }

    public function failsOnAnUnknownOperation(): void
    {
        $dir = $this->fixtures([
            'undeclared-route.json' => [
                'request' => ['method' => 'GET', 'uri' => 'https://api.test/cats/7'],
            ],
        ]);

        $exit = $this->command()->run([$this->contractFile(), $dir]);

        Assert::same($exit, ValidateCommand::EXIT_VIOLATIONS);
        Assert::same($this->lines[0], 'FAIL  undeclared-route.json');
        Assert::true(str_contains(implode("\n", $this->lines), 'request.operation.unknown'));
    }

    public function failsOnAMalformedFixture(): void
    {
        $dir = $this->fixtures([
            'not-json.json' => '{broken',
            'no-request.json' => '{"name": "a fixture without a request"}',
            'no-method.json' => '{"request": {"uri": "https://api.test/pets/7"}}',
            'bad-response-status.json' => '{"request": {"method": "GET", "uri": "https://api.test/pets/7"}, "response": {"status": "200"}}',
        ]);

        $exit = $this->command()->run([$this->contractFile(), $dir]);

        Assert::same($exit, ValidateCommand::EXIT_VIOLATIONS);
        Assert::same($this->lines[0], 'FAIL  bad-response-status.json');
        Assert::same($this->lines[2], 'FAIL  no-method.json');
        Assert::same($this->lines[4], 'FAIL  no-request.json');
        Assert::same($this->lines[6], 'FAIL  not-json.json');
        Assert::same($this->lines[count($this->lines) - 1], '0 passed, 4 failed');
    }

    public function usageErrorsAnswerExitCodeTwo(): void
    {
        $command = $this->command();

        Assert::same($command->run([]), ValidateCommand::EXIT_USAGE);
        Assert::same($command->run(['/no/such/contract.json', '/no/such/dir']), ValidateCommand::EXIT_USAGE);

        $empty = $this->fixtures([]);
        Assert::same($command->run([$this->contractFile(), $empty]), ValidateCommand::EXIT_USAGE);
        Assert::true(str_contains(implode("\n", $this->lines), 'No *.json fixtures found'));

        $broken = $this->fixtures(['ignored.json' => '{}']);
        file_put_contents($broken . '/contract.json', '{"openapi": "3.1.0"}');
        $this->lines = [];
        Assert::same($command->run([$broken . '/contract.json', $broken]), ValidateCommand::EXIT_USAGE);
        Assert::true(str_contains(implode("\n", $this->lines), 'Cannot load contract'));
    }

    public function mixedDirectoryReportsBothCounts(): void
    {
        $dir = $this->fixtures([
            'a-valid.json' => [
                'request' => ['method' => 'GET', 'uri' => 'https://api.test/pets/7'],
            ],
            'z-invalid.json' => [
                'request' => ['method' => 'GET', 'uri' => 'https://api.test/pets/0'],
            ],
        ]);

        $exit = $this->command()->run([$this->contractFile(), $dir]);

        Assert::same($exit, ValidateCommand::EXIT_VIOLATIONS);
        Assert::same($this->lines[0], 'PASS  a-valid.json');
        Assert::same($this->lines[1], 'FAIL  z-invalid.json');
        Assert::same($this->lines[count($this->lines) - 1], '1 passed, 1 failed');
    }

    private function command(): ValidateCommand
    {
        $this->lines = [];

        return new ValidateCommand(
            write: function (string $line): void {
                $this->lines[] = $line;
            },
        );
    }

    private function contractFile(): string
    {
        static $path = null;
        $path ??= $this->fixtures(['contract.json' => (string) json_encode([
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
        ])]) . '/contract.json';

        return $path;
    }

    /**
     * @param array<string, string|array<string, mixed>> $files
     */
    private function fixtures(array $files): string
    {
        $dir = sys_get_temp_dir() . '/openapi-contract-test-' . bin2hex(random_bytes(6));
        mkdir($dir, recursive: true);
        foreach ($files as $name => $content) {
            $encoded = is_array($content)
                ? json_encode($content, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : $content;
            file_put_contents($dir . '/' . $name, $encoded);
        }

        return $dir;
    }
}
