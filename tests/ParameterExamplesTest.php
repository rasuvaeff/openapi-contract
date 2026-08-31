<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Nyholm\Psr7\Request;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Internal\Compilation\DocumentCompiler;
use Rasuvaeff\OpenApiContract\InvalidContract;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(DocumentCompiler::class)]
final class ParameterExamplesTest
{
    public function carriesDeclaredParameterExamplesThroughCompilation(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'components' => ['examples' => ['big' => ['summary' => 'big', 'value' => 900]]],
            'paths' => ['/items' => [
                'parameters' => [[
                    'name' => 'page',
                    'in' => 'query',
                    'schema' => ['type' => 'integer'],
                    'example' => 3,
                ]],
                'get' => [
                    'operationId' => 'items.list',
                    'parameters' => [[
                        'name' => 'limit',
                        'in' => 'query',
                        'schema' => ['type' => 'integer'],
                        'examples' => [
                            'small' => ['value' => 1],
                            'large' => ['$ref' => '#/components/examples/big'],
                        ],
                    ]],
                    'responses' => ['200' => ['description' => 'ok']],
                ],
            ]],
        ]);

        $parameters = $contract->operation('items.list')->parameters;

        Assert::same($parameters[0]['name'], 'page');
        Assert::same($parameters[0]['example'] ?? null, 3);
        Assert::false(array_key_exists('examples', $parameters[0]));

        Assert::same($parameters[1]['name'], 'limit');
        Assert::false(array_key_exists('example', $parameters[1]));
        Assert::same($parameters[1]['examples'] ?? null, [
            'small' => ['value' => 1],
            'large' => ['summary' => 'big', 'value' => 900],
        ]);
    }

    public function omitsExampleKeysWhenTheDocumentDeclaresNone(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/items' => ['get' => [
            'operationId' => 'items.list',
            'parameters' => [['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer']]],
            'responses' => ['200' => ['description' => 'ok']],
        ]]]]);

        $parameter = $contract->operation('items.list')->parameters[0];

        Assert::false(array_key_exists('example', $parameter));
        Assert::false(array_key_exists('examples', $parameter));
    }

    public function keepsANullExampleDistinctFromAnAbsentOne(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/items' => ['get' => [
            'operationId' => 'items.list',
            'parameters' => [['name' => 'tag', 'in' => 'query', 'schema' => ['type' => 'string'], 'example' => null]],
            'responses' => ['200' => ['description' => 'ok']],
        ]]]]);

        $parameter = $contract->operation('items.list')->parameters[0];

        Assert::true(array_key_exists('example', $parameter));
        Assert::null($parameter['example']);
    }

    #[DataProvider('malformedExamplesProvider')]
    public function rejectsMalformedExamplesMaps(mixed $examples): void
    {
        try {
            Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/items' => ['get' => [
                'parameters' => [['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'string'], 'examples' => $examples]],
                'responses' => ['200' => ['description' => 'ok']],
            ]]]]);
            Assert::true(actual: false, message: 'Expected a malformed examples exception');
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI parameter "limit" examples must be a map of named examples');
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedExamplesProvider(): iterable
    {
        yield 'scalar' => [5];
        yield 'list' => [[['value' => 1]]];
        yield 'mixed integer key' => [['ok' => ['value' => 1], 7 => ['value' => 2]]];
    }

    public function examplesStayAnnotationsForValidation(): void
    {
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/items' => ['get' => [
            'operationId' => 'items.list',
            'parameters' => [[
                'name' => 'limit',
                'in' => 'query',
                'required' => true,
                'schema' => ['type' => 'integer'],
                'example' => 'not-an-integer',
            ]],
            'responses' => ['200' => ['description' => 'ok']],
        ]]]]);

        Assert::true($contract->validateRequest(new Request('GET', '/items?limit=5'))->isValid());
    }
}
