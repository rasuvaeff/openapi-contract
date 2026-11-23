<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Nyholm\Psr7\Request;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\ContractException;
use Rasuvaeff\OpenApiContract\Internal\Compilation\DocumentCompiler;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\OpenApiContract\UnknownOperation;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Contract::class)]
#[Covers(DocumentCompiler::class)]
#[Covers(Operation::class)]
final class OperationSelectionTest
{
    public function anUnsupportedOperationRefusesTheWholeDocument(): void
    {
        $caught = null;

        try {
            Contract::fromArray($this->document());
        } catch (ContractException $caught) {
        }

        Assert::notNull($caught);
    }

    public function unselectedOperationsAreNotCompiled(): void
    {
        $contract = Contract::fromArray($this->document(), only: ['listTags', 'GET /plain']);

        Assert::same($this->keys($contract), ['listTags', 'GET /plain']);
        Assert::same($contract->webhooks(), []);
        Assert::null($contract->match(new Request('POST', '/tags')));
        Assert::same($contract->match(new Request('GET', '/tags'))?->operation->key, 'listTags');
    }

    public function everyFactoryTakesTheSelection(): void
    {
        $json = json_encode($this->document(), JSON_THROW_ON_ERROR);
        $path = sys_get_temp_dir() . '/contract-selection-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, $json);

        try {
            Assert::same($this->keys(Contract::fromJson($json, only: ['listTags'])), ['listTags']);
            Assert::same($this->keys(Contract::fromFile($path, only: ['listTags'])), ['listTags']);
        } finally {
            unlink($path);
        }
    }

    public function anOperationOfAReferencedPathItemCanBeSelected(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => [
                '/plain' => ['get' => ['responses' => ['204' => ['description' => 'ok']]]],
                '/shared' => ['$ref' => '#/components/pathItems/shared'],
            ],
            'components' => ['pathItems' => ['shared' => [
                'get' => ['operationId' => 'shared', 'responses' => ['204' => ['description' => 'ok']]],
                'post' => ['operationId' => 'other', 'responses' => ['204' => ['description' => 'ok']]],
            ]]],
        ], only: ['shared']);

        Assert::same($this->keys($contract), ['shared']);
    }

    public function aKeyTheDocumentDoesNotDeclareIsAnError(): void
    {
        $caught = null;

        try {
            Contract::fromArray($this->document(), only: ['listTags', 'missing']);
        } catch (UnknownOperation $caught) {
        }

        Assert::notNull($caught);
        Assert::same($caught->getMessage(), 'Operation "missing" is not present in the OpenAPI document');
    }

    public function anOperationWithAnIdIsNotSelectableByMethodAndPath(): void
    {
        $caught = null;

        try {
            Contract::fromArray($this->document(), only: ['GET /tags']);
        } catch (UnknownOperation $caught) {
        }

        Assert::notNull($caught);
    }

    public function annotationsAreCarried(): void
    {
        $operation = Contract::fromArray($this->document(), only: ['listTags'])->operation('listTags');

        Assert::same($operation->summary, 'List tags');
        Assert::same($operation->description, 'Every tag there is');
        Assert::same($operation->tags, ['catalog', 'read']);
        Assert::same($operation->parameters[0]['description'] ?? null, 'Language');
        Assert::false(array_key_exists('description', $operation->parameters[1]));
    }

    public function absentOrMalformedAnnotationsAreEmpty(): void
    {
        $document = $this->document();
        $document['paths']['/plain']['get'] += ['summary' => 7, 'description' => '', 'tags' => 'catalog', 'parameters' => [['name' => 'q', 'in' => 'query', 'description' => 7, 'schema' => []]]];
        $operation = Contract::fromArray($document, only: ['GET /plain'])->operation('GET /plain');

        Assert::null($operation->summary);
        Assert::null($operation->description);
        Assert::same($operation->tags, []);
        Assert::false(array_key_exists('description', $operation->parameters[0]));
    }

    public function webhookAnnotationsAreCarried(): void
    {
        $document = $this->document();
        $document['paths'] = [];
        $operation = Contract::fromArray($document)->webhooks()['tagAdded'][0];

        Assert::same([$operation->summary, $operation->description, $operation->tags], ['Added', 'A tag appeared', ['events']]);
    }

    /** @return list<string> */
    private function keys(Contract $contract): array
    {
        return array_map(static fn(Operation $operation): string => $operation->key, $contract->operations());
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        $ok = ['responses' => ['204' => ['description' => 'ok']]];

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 't', 'version' => '1'],
            'paths' => [
                '/tags' => [
                    'get' => [
                        'operationId' => 'listTags',
                        'summary' => 'List tags',
                        'description' => 'Every tag there is',
                        'tags' => ['catalog', 7, '', 'read'],
                        'parameters' => [
                            ['name' => 'locale', 'in' => 'query', 'description' => 'Language', 'schema' => ['type' => 'string']],
                            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                        ],
                    ] + $ok,
                    'post' => ['operationId' => 'broken', 'parameters' => [['$ref' => '#/components/parameters/nowhere']]] + $ok,
                ],
                '/plain' => ['get' => $ok],
                '/broken' => ['get' => ['operationId' => 'alsoBroken', 'requestBody' => ['$ref' => 'https://example.test/x.json']] + $ok],
            ],
            'webhooks' => ['tagAdded' => ['post' => ['summary' => 'Added', 'description' => 'A tag appeared', 'tags' => ['events']] + $ok]],
        ];
    }
}
