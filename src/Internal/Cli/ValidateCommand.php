<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Cli;

use Closure;
use JsonException;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\ValidationResult;
use Rasuvaeff\OpenApiContract\ValidationResultFormatter;

/**
 * The engine behind `bin/openapi-contract`: validates a directory of JSON
 * fixtures against one contract document and answers a CI-friendly exit
 * code — 0 when every fixture holds, 1 when any fixture violates the
 * contract or is itself malformed, 2 when the command was misused (bad
 * arguments, unloadable contract, no fixtures to run).
 *
 * A fixture is a JSON object with a `request` (`method`, `uri`, optional
 * `headers` map and `body` string) and an optional `response` (`status`,
 * the same `headers`/`body`). With a `response` the whole exchange is
 * validated, without it the request alone. The `name` key is optional
 * prose the report does not read.
 *
 * @internal
 */
final readonly class ValidateCommand
{
    public const int EXIT_OK = 0;
    public const int EXIT_VIOLATIONS = 1;
    public const int EXIT_USAGE = 2;

    /**
     * @param Closure(string): void $write one report line at a time, without
     *        the trailing newline — the caller decides where output goes
     */
    public function __construct(
        private Closure $write,
        private ValidationResultFormatter $formatter = new ValidationResultFormatter(),
    ) {}

    /**
     * @param list<string> $args the arguments after the script name:
     *        `<contract-file> <fixtures-dir>`
     */
    public function run(array $args): int
    {
        if (!class_exists(\Nyholm\Psr7\Request::class)) {
            $this->line('The CLI runner needs a PSR-7 implementation that is not installed.');
            $this->line('Install one: composer require nyholm/psr7');

            return self::EXIT_USAGE;
        }

        if (count($args) !== 2 || !is_file($args[0]) || !is_dir($args[1])) {
            $this->line('Usage: openapi-contract <contract-file> <fixtures-dir>');
            $this->line('  <contract-file>  path to the OpenAPI document (JSON or YAML)');
            $this->line('  <fixtures-dir>  directory of *.json fixtures to validate');

            return self::EXIT_USAGE;
        }

        try {
            $contract = Contract::fromFile($args[0]);
        } catch (\Throwable $e) {
            $this->line('Cannot load contract: ' . $e->getMessage());

            return self::EXIT_USAGE;
        }

        $found = glob(rtrim($args[1], '/') . '/*.json');
        $files = is_array($found) ? $found : [];
        if ($files === []) {
            $this->line('No *.json fixtures found in ' . $args[1]);

            return self::EXIT_USAGE;
        }
        sort($files, SORT_STRING);

        $passed = 0;
        $failed = 0;
        foreach ($files as $file) {
            $report = $this->validateFixture($contract, $file);
            if ($report === null) {
                ++$passed;
                $this->line('PASS  ' . basename($file));

                continue;
            }
            ++$failed;
            $this->line('FAIL  ' . basename($file));
            foreach (explode("\n", $report) as $line) {
                $this->line('  ' . $line);
            }
        }
        $this->line(sprintf('%d passed, %d failed', $passed, $failed));

        return $failed === 0 ? self::EXIT_OK : self::EXIT_VIOLATIONS;
    }

    /**
     * @return string|null the rendered report when the fixture fails — a
     *         broken fixture and a violated contract render the same way,
     *         both are this runner's red — or null when it holds
     */
    private function validateFixture(Contract $contract, string $file): ?string
    {
        try {
            /** @var mixed $fixture */
            $fixture = json_decode((string) file_get_contents($file), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return 'Fixture is not valid JSON: ' . $e->getMessage();
        }
        if (!is_array($fixture)) {
            return 'Fixture must be a JSON object with a "request" member';
        }
        /** @var mixed $requestFixture */
        $requestFixture = $fixture['request'] ?? null;
        if (!is_array($requestFixture)) {
            return 'Fixture must be a JSON object with a "request" member';
        }

        try {
            $request = $this->request($requestFixture);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        if (!array_key_exists('response', $fixture)) {
            return $this->render($contract->validateRequest($request), 'request');
        }

        try {
            $response = $this->response($fixture['response']);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return $this->render($contract->validateExchange($request, $response), 'exchange');
    }

    /**
     * @param array<mixed> $fixture
     */
    private function request(array $fixture): Request
    {
        $method = $fixture['method'] ?? null;
        $uri = $fixture['uri'] ?? null;
        if (!is_string($method) || $method === '' || !is_string($uri) || $uri === '') {
            throw new \InvalidArgumentException('Fixture request needs non-empty string "method" and "uri"');
        }

        /** @var array<string, string> $headers */
        $headers = is_array($fixture['headers'] ?? null) ? $fixture['headers'] : [];
        /** @var mixed $rawBody */
        $rawBody = $fixture['body'] ?? null;
        $body = is_string($rawBody) ? $rawBody : null;

        return new Request($method, $uri, $headers, $body);
    }

    private function response(mixed $fixture): Response
    {
        if (!is_array($fixture)) {
            throw new \InvalidArgumentException('Fixture response needs an integer "status"');
        }
        /** @var mixed $status */
        $status = $fixture['status'] ?? null;
        if (!is_int($status)) {
            throw new \InvalidArgumentException('Fixture response needs an integer "status"');
        }

        /** @var array<string, string> $headers */
        $headers = is_array($fixture['headers'] ?? null) ? $fixture['headers'] : [];
        /** @var mixed $rawBody */
        $rawBody = $fixture['body'] ?? null;
        $body = is_string($rawBody) ? $rawBody : null;

        return new Response($status, $headers, $body);
    }

    private function render(ValidationResult $result, string $what): ?string
    {
        if ($result->isValid()) {
            return null;
        }

        return sprintf('%s: %s', $what, $this->formatter->format($result));
    }

    private function line(string $line): void
    {
        ($this->write)($line);
    }
}
