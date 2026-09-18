<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Internal\Compilation\OperationSchemas;
use Rasuvaeff\OpenApiContract\Operation;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(OperationSchemas::class)]
final class OperationSchemasTest
{
    /**
     * Every schema at every position, in the direction it is read in: each
     * of two part headers, each of two response headers, each of two media
     * types, and each of two responses.
     */
    public function yieldsEverySchemaAtEveryPosition(): void
    {
        $operation = new Operation(
            key: 'POST /b',
            operationId: null,
            method: 'POST',
            path: '/b',
            parameters: [$this->parameter(['const' => 'q'])],
            requestBody: ['content' => [
                'application/json' => ['schema' => ['const' => 'json']],
                'multipart/form-data' => ['schema' => ['const' => 'multipart'], 'encoding' => [
                    'file' => ['headers' => ['X-A' => ['schema' => ['const' => 'part-a']], 'X-B' => ['schema' => ['const' => 'part-b']]]],
                    'meta' => ['headers' => ['X-C' => ['schema' => ['const' => 'part-c']]]],
                ]],
            ]],
            responses: [
                200 => ['headers' => ['X-A' => ['schema' => ['const' => 'header-a']], 'X-B' => ['schema' => ['const' => 'header-b']]], 'content' => ['application/json' => ['schema' => ['const' => 'ok']]]],
                'default' => ['content' => ['text/plain' => ['schema' => ['const' => 'fallback']]]],
            ],
        );

        Assert::same($this->sites($operation), [
            'q@Request', 'json@Request', 'multipart@Request', 'part-a@Request', 'part-b@Request', 'part-c@Request',
            'ok@Response', 'header-a@Response', 'header-b@Response', 'fallback@Response',
        ]);
    }

    /**
     * What is not a Schema Object the validators would read is not yielded:
     * a boolean, a list, a map with a non-string key, and any container that
     * is not the shape the compiler guarantees. The empty schema is one.
     */
    public function skipsWhatIsNotASchemaObject(): void
    {
        $operation = new Operation(
            key: 'POST /b',
            operationId: null,
            method: 'POST',
            path: '/b',
            requestBody: ['content' => [
                'a' => ['schema' => true],
                'b' => ['schema' => ['x', 'y']],
                'c' => ['schema' => [0 => 'x', 'const' => 'int-key']],
                'd' => ['schema' => []],
                'e' => 'not-a-media-type',
                'f' => ['encoding' => 'not-an-object'],
                'g' => ['encoding' => ['p' => 'not-an-object', 'r' => ['headers' => 'not-an-object'], 'h' => ['headers' => ['X' => 'not-an-object', 'Y' => ['schema' => ['const' => 'after-a-bad-header']]]], 'i' => ['headers' => ['Z' => ['schema' => ['const' => 'after-a-bad-encoding']]]]]],
                'z' => ['schema' => ['const' => 'after-a-bad-media-type']],
            ]],
            responses: [200 => 'not-an-object', 201 => ['content' => 'not-an-object', 'headers' => 'not-an-object'], 204 => [], 202 => ['content' => ['application/json' => ['schema' => ['const' => 'after-a-bad-response']]]]],
        );

        // What is skipped is skipped alone: the walk goes on past it.
        Assert::same($this->sites($operation), ['{}@Request', 'after-a-bad-header@Request', 'after-a-bad-encoding@Request', 'after-a-bad-media-type@Request', 'after-a-bad-response@Response']);
        Assert::same($this->sites(new Operation(key: 'GET /n', operationId: null, method: 'GET', path: '/n', requestBody: ['content' => 'x'])), []);
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function parameter(array $schema): array
    {
        return ['name' => 'q', 'in' => 'query', 'required' => false, 'style' => 'form', 'explode' => true, 'allowReserved' => false, 'schema' => $schema, 'specPointer' => '/paths/~1b/post/parameters/0'];
    }

    /** @return list<string> */
    private function sites(Operation $operation): array
    {
        $sites = [];
        foreach ((new OperationSchemas())->of($operation) as [$schema, $direction]) {
            $sites[] = (is_string($schema['const'] ?? null) ? $schema['const'] : '{}') . '@' . $direction->name;
        }

        return $sites;
    }
}
