<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedReference;
use Rasuvaeff\OpenApiContract\Internal\Reference\DocumentGraph;
use Rasuvaeff\OpenApiContract\Internal\Reference\JsonPointerResolver;
use Rasuvaeff\OpenApiContract\InvalidContract;
use Rasuvaeff\OpenApiContract\Limits;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Contract::class)]
#[Covers(DocumentGraph::class)]
#[Covers(JsonPointerResolver::class)]
final class MultiFileContractTest
{
    public function resolvesRelativeRefsAcrossJsonAndYamlFiles(): void
    {
        $root = $this->workspace();

        try {
            $this->writePetTree($root);
            $contract = Contract::fromFile($root . '/entry.json');

            $operation = $contract->operation('getPet');
            Assert::same($operation->parameters[0]['name'], 'petId');
            Assert::same($operation->parameters[0]['schema'], ['type' => 'integer']);

            $valid = $contract->validateResponse('getPet', new \Nyholm\Psr7\Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: '{"id": 7}',
            ));
            Assert::true($valid->isValid());

            $invalid = $contract->validateResponse('getPet', new \Nyholm\Psr7\Response(
                status: 200,
                headers: ['Content-Type' => 'application/json'],
                body: '{"id": "not-an-id"}',
            ));
            Assert::false($invalid->isValid());
        } finally {
            $this->remove($root);
        }
    }

    public function compilesIdenticallyAfterRootRelocation(): void
    {
        $first = $this->workspace();
        $second = $this->workspace();

        try {
            $this->writePetTree($first);
            $this->writePetTree($second);

            Assert::same(
                serialize(Contract::fromFile($first . '/entry.json')->operations()),
                serialize(Contract::fromFile($second . '/entry.json')->operations()),
            );
        } finally {
            $this->remove($first);
            $this->remove($second);
        }
    }

    public function supportsWholeFileRefsAndEscapedPointerTokens(): void
    {
        $root = $this->workspace();

        try {
            $this->write($root, 'entry.json', json_encode([
                'openapi' => '3.1.0',
                'paths' => ['/items' => ['get' => [
                    'operationId' => 'listItems',
                    'parameters' => [
                        ['name' => 'plain', 'in' => 'query', 'schema' => ['$ref' => 'item-schema.json']],
                        ['name' => 'escaped', 'in' => 'query', 'schema' => ['$ref' => 'shared.json#/name~1with~0chars']],
                        ['name' => 'shouty', 'in' => 'query', 'schema' => ['$ref' => 'SCHEMA.YAML#/X']],
                    ],
                    'responses' => ['200' => ['description' => 'ok']],
                ]]],
            ], JSON_THROW_ON_ERROR));
            $this->write($root, 'item-schema.json', '{"type": "string"}');
            $this->write($root, 'shared.json', json_encode(['name/with~chars' => ['type' => 'boolean']], JSON_THROW_ON_ERROR));
            $this->write($root, 'SCHEMA.YAML', "Y:\n  type: string\nX:\n  type: number\n");

            $operation = Contract::fromFile($root . '/entry.json')->operation('listItems');
            Assert::same($operation->parameters[0]['schema'], ['type' => 'string']);
            Assert::same($operation->parameters[1]['schema'], ['type' => 'boolean']);
            Assert::same($operation->parameters[2]['schema'], ['type' => 'number']);
        } finally {
            $this->remove($root);
        }
    }

    public function reusesAParsedFileAcrossMultipleRefs(): void
    {
        $root = $this->workspace();

        try {
            $this->write($root, 'entry.json', json_encode([
                'openapi' => '3.1.0',
                'paths' => ['/items' => ['get' => [
                    'operationId' => 'listItems',
                    'parameters' => [
                        ['name' => 'first', 'in' => 'query', 'schema' => ['$ref' => 'shared.json#/A']],
                        ['name' => 'second', 'in' => 'query', 'schema' => ['$ref' => 'shared.json#/B']],
                    ],
                    'responses' => ['200' => ['description' => 'ok']],
                ]]],
            ], JSON_THROW_ON_ERROR));
            $this->write($root, 'shared.json', json_encode([
                'A' => ['type' => 'integer'],
                'B' => ['$ref' => '#/A'],
            ], JSON_THROW_ON_ERROR));

            $operation = Contract::fromFile($root . '/entry.json')->operation('listItems');
            Assert::same($operation->parameters[0]['schema'], ['type' => 'integer']);
            Assert::same($operation->parameters[1]['schema'], ['type' => 'integer']);
        } finally {
            $this->remove($root);
        }
    }

    public function detectsCrossFileCyclesViaTheDepthBudget(): void
    {
        $root = $this->workspace();

        try {
            $this->write($root, 'entry.json', $this->entryWithParameterSchemaRef('a.json#/A'));
            $this->write($root, 'a.json', '{"A": {"$ref": "b.json#/B"}}');
            $this->write($root, 'b.json', '{"B": {"$ref": "a.json#/A"}}');

            Contract::fromFile($root . '/entry.json');
            Assert::true(actual: false, message: 'Expected reference depth exception');
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI $ref chain is too deep (possible circular reference)');
        } finally {
            $this->remove($root);
        }
    }

    #[DataProvider('rejectedReferenceProvider')]
    public function rejectsUnsafeFileReferencesBeforeReading(string $reference, string $messagePart): void
    {
        $root = $this->workspace();

        try {
            $this->write($root, 'entry.json', $this->entryWithParameterSchemaRef($reference));

            Contract::fromFile($root . '/entry.json');
            Assert::true(actual: false, message: 'Expected rejected reference exception');
        } catch (InvalidContract $exception) {
            Assert::string($exception->getMessage())->contains($messagePart);
        } finally {
            $this->remove($root);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejectedReferenceProvider(): iterable
    {
        yield 'absolute path' => ['/etc/openapi.json#/X', 'must use a relative path'];
        yield 'https URI' => ['https://example.test/x.json#/X', 'targets a remote or non-file URI'];
        yield 'file URI' => ['file:///x.json#/X', 'targets a remote or non-file URI'];
        yield 'protocol-relative URI' => ['//host/x.json#/X', 'targets a remote or non-file URI'];
        yield 'backslash path' => ['..\\x.json#/X', 'uses an unsupported file path form'];
        yield 'percent-encoded path' => ['x%2e.json#/X', 'uses an unsupported file path form'];
        yield 'missing file' => ['nope.json#/X', 'references a missing file'];
        yield 'directory target' => ['sub#/X', 'references a missing file'];
    }

    public function rejectsTraversalAndSymlinkEscapesOutsideTheRoot(): void
    {
        $outer = $this->workspace();
        $root = $outer . '/root';

        try {
            if (!mkdir($root, 0o700)) {
                throw new \RuntimeException('Unable to create document root');
            }
            $this->write($outer, 'outside.json', '{"X": {"type": "string"}}');
            $this->write($outer, 'root-evil/schema.json', '{"X": {"type": "string"}}');

            $this->write($root, 'entry.json', $this->entryWithParameterSchemaRef('../root-evil/schema.json#/X'));

            try {
                Contract::fromFile($root . '/entry.json');
                Assert::true(actual: false, message: 'Expected prefix-sibling escape exception');
            } catch (InvalidContract $exception) {
                Assert::string($exception->getMessage())->contains('escapes the document root');
            }

            $this->write($root, 'entry.json', $this->entryWithParameterSchemaRef('../outside.json#/X'));

            try {
                Contract::fromFile($root . '/entry.json');
                Assert::true(actual: false, message: 'Expected traversal escape exception');
            } catch (InvalidContract $exception) {
                Assert::string($exception->getMessage())->contains('escapes the document root');
            }

            if (!symlink($outer . '/outside.json', $root . '/link.json')) {
                throw new \RuntimeException('Unable to create symlink');
            }
            $this->write($root, 'entry.json', $this->entryWithParameterSchemaRef('link.json#/X'));

            try {
                Contract::fromFile($root . '/entry.json');
                Assert::true(actual: false, message: 'Expected symlink escape exception');
            } catch (InvalidContract $exception) {
                Assert::string($exception->getMessage())->contains('escapes the document root');
            }
        } finally {
            $this->remove($outer);
        }
    }

    public function reportsRootRelativePathsWithoutLeakingTheHostLocation(): void
    {
        $root = $this->workspace();

        try {
            $this->write($root, 'entry.json', $this->entryWithParameterSchemaRef('components/a.json#/A'));
            $this->write($root, 'components/a.json', '{"A": {"$ref": "schemas.json#/Missing"}}');
            $this->write($root, 'components/schemas.json', '{"Present": {"type": "string"}}');

            Contract::fromFile($root . '/entry.json');
            Assert::true(actual: false, message: 'Expected unresolvable pointer exception');
        } catch (InvalidContract $exception) {
            Assert::same(
                $exception->getMessage(),
                'Unresolvable $ref "schemas.json#/Missing" in OpenAPI document "components/a.json"',
            );
            Assert::false(str_contains($exception->getMessage(), $root));
        } finally {
            $this->remove($root);
        }
    }

    /**
     * A YAML file that weighs a few hundred bytes and expands into millions of
     * nodes: the byte budget cannot see it, and the resolver's own budget
     * never counts it, because the anchor is used where the resolver rightly
     * stops — inside `enum`, which is data.
     */
    public function refusesAYamlDocumentThatExpandsPastTheNodeBudget(): void
    {
        $root = $this->workspace();

        try {
            $yaml = "openapi: 3.1.0\n";
            $yaml .= "x-anchors:\n  a0: &a0 [\"a\",\"b\",\"c\",\"d\",\"e\",\"f\",\"g\",\"h\",\"i\"]\n";
            for ($level = 1; $level <= 5; $level++) {
                $previous = '*a' . ($level - 1);
                $yaml .= sprintf("  a%d: &a%d [%s]\n", $level, $level, implode(',', array_fill(0, 9, $previous)));
            }
            $yaml .= "paths:\n  /h:\n    get:\n      parameters:\n        - name: q\n          in: query\n          schema:\n            type: string\n            enum: *a5\n";
            $yaml .= "      responses:\n        '200':\n          description: ok\n";
            $this->write($root, 'bomb.yaml', $yaml);

            Assert::true(strlen($yaml) < 1024);

            try {
                // 9^6 nodes out of under a kilobyte: the budget is what the
                // document expands into, and counting stops at it, so the
                // refusal costs the budget rather than the expansion.
                Contract::fromFile($root . '/bomb.yaml', new Limits(documentNodes: 100_000));
                Assert::true(actual: false, message: 'Expected the node budget to refuse the expanded document');
            } catch (InvalidContract $exception) {
                Assert::same($exception->getMessage(), 'OpenAPI document "bomb.yaml" exceeds the shared node budget');
            }
        } finally {
            $this->remove($root);
        }
    }

    public function passesTheCallersBudgetsIntoTheDocumentGraph(): void
    {
        $root = $this->workspace();

        try {
            $this->write($root, 'entry.json', $this->entryWithParameterSchemaRef('shared.json#/A'));
            $this->write($root, 'shared.json', '{"A": {"type": "integer"}}');

            Assert::same(count(Contract::fromFile($root . '/entry.json')->operations()), 1);

            try {
                Contract::fromFile($root . '/entry.json', new Limits(documentFiles: 1));
                Assert::true(actual: false, message: 'Expected the configured file budget to refuse the graph');
            } catch (InvalidContract $exception) {
                Assert::same($exception->getMessage(), 'OpenAPI document graph exceeds the budget of 1 files');
            }

            try {
                Contract::fromFile($root . '/entry.json', new Limits(documentNodes: 2));
                Assert::true(actual: false, message: 'Expected the configured node budget to refuse the graph');
            } catch (InvalidContract $exception) {
                Assert::string($exception->getMessage())->contains('exceeds the shared node budget');
            }

            try {
                Contract::fromFile($root . '/entry.json', new Limits(documentBytes: 1));
                Assert::true(actual: false, message: 'Expected the configured byte budget to refuse the graph');
            } catch (InvalidContract $exception) {
                Assert::string($exception->getMessage())->contains('exceeds the shared byte budget');
            }
        } finally {
            $this->remove($root);
        }
    }

    public function enforcesTheSharedFileAndByteBudgets(): void
    {
        $root = $this->workspace();

        try {
            $this->write($root, 'entry.json', $this->entryWithParameterSchemaRef('shared.json#/A'));
            $this->write($root, 'shared.json', '{"A": {"type": "integer"}}');

            $files = DocumentGraph::open($root . '/entry.json', maximumFiles: 1);
            $shared = realpath($root . '/shared.json');
            $entry = realpath($root . '/entry.json');
            if ($shared === false || $entry === false) {
                throw new \RuntimeException('Unable to canonicalize the fixture path');
            }

            Assert::same($files->document($entry), $files->entryDocument());

            try {
                $files->document($shared);
                Assert::true(actual: false, message: 'Expected file-count budget exception');
            } catch (InvalidContract $exception) {
                Assert::same($exception->getMessage(), 'OpenAPI document graph exceeds the budget of 1 files');
            }

            $entrySize = filesize($root . '/entry.json');
            if (!is_int($entrySize)) {
                throw new \RuntimeException('Unable to size the fixture entry file');
            }
            $bytes = DocumentGraph::open($root . '/entry.json', maximumBytes: $entrySize);

            try {
                $bytes->document($shared);
                Assert::true(actual: false, message: 'Expected byte budget exception');
            } catch (InvalidContract $exception) {
                Assert::same($exception->getMessage(), 'OpenAPI document "shared.json" exceeds the shared byte budget');
            }

            try {
                DocumentGraph::open($root . '/entry.json', maximumBytes: $entrySize - 1);
                Assert::true(actual: false, message: 'Expected entry byte budget exception');
            } catch (InvalidContract $exception) {
                Assert::string($exception->getMessage())->contains('exceeds the shared byte budget');
            }

            try {
                DocumentGraph::open($root . '/entry.json', maximumBytes: 1);
                Assert::true(actual: false, message: 'Expected minimal byte budget exception');
            } catch (InvalidContract $exception) {
                Assert::string($exception->getMessage())->contains('exceeds the shared byte budget');
            }

            try {
                DocumentGraph::open($root);
                Assert::true(actual: false, message: 'Expected directory entry exception');
            } catch (InvalidContract $exception) {
                Assert::same($exception->getMessage(), sprintf('OpenAPI document "%s" is not readable', $root));
            }
        } finally {
            $this->remove($root);
        }
    }

    public function rejectsScalarSiblingDocuments(): void
    {
        $root = $this->workspace();

        try {
            $this->write($root, 'scalar.json', '42');
            $this->write($root, 'scalar.yaml', '42');

            $this->write($root, 'entry.json', $this->entryWithParameterSchemaRef('scalar.json#/X'));

            try {
                Contract::fromFile($root . '/entry.json');
                Assert::true(actual: false, message: 'Expected scalar JSON sibling exception');
            } catch (InvalidContract $exception) {
                Assert::same($exception->getMessage(), 'OpenAPI document "scalar.json" must decode to an object');
            }

            $this->write($root, 'entry.json', $this->entryWithParameterSchemaRef('scalar.yaml#/X'));

            try {
                Contract::fromFile($root . '/entry.json');
                Assert::true(actual: false, message: 'Expected scalar YAML sibling exception');
            } catch (InvalidContract $exception) {
                Assert::same($exception->getMessage(), 'OpenAPI YAML document "scalar.yaml" must decode to an object');
            }
        } finally {
            $this->remove($root);
        }
    }

    public function honorsTheJsonDepthBudgetOnFileLoadedDocuments(): void
    {
        $root = $this->workspace();
        $nested = static fn(int $depth): string => sprintf(
            '{"openapi":"3.1.0","x-deep":%s1%s,"paths":{"/h":{"get":{"responses":{"200":{"description":"ok"}}}}}}',
            str_repeat('[', $depth),
            str_repeat(']', $depth),
        );

        try {
            $this->write($root, 'shallow.json', $nested(62));
            Assert::same(Contract::fromFile($root . '/shallow.json')->operations()[0]->path, '/h');

            $this->write($root, 'deep.json', $nested(63));

            try {
                Contract::fromFile($root . '/deep.json');
                Assert::true(actual: false, message: 'Expected JSON depth exception');
            } catch (InvalidContract $exception) {
                Assert::string($exception->getMessage())->contains('is not valid JSON');
            }
        } finally {
            $this->remove($root);
        }
    }

    public function rejectsNonPositiveGraphBudgets(): void
    {
        try {
            DocumentGraph::open('irrelevant.json', maximumFiles: 0);
            Assert::true(actual: false, message: 'Expected file budget guard exception');
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), 'Maximum file count must be positive');
        }

        try {
            DocumentGraph::open('irrelevant.json', maximumBytes: 0);
            Assert::true(actual: false, message: 'Expected byte budget guard exception');
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), 'Maximum byte budget must be positive');
        }
    }

    public function keepsArrayAndJsonEntrypointsFailClosedForFileRefs(): void
    {
        try {
            Contract::fromArray([
                'openapi' => '3.1.0',
                'paths' => ['/items' => ['get' => [
                    'parameters' => [['name' => 'q', 'in' => 'query', 'schema' => ['$ref' => 'shared.json#/A']]],
                    'responses' => ['200' => ['description' => 'ok']],
                ]]],
            ]);
            Assert::true(actual: false, message: 'Expected unsupported reference exception');
        } catch (UnsupportedReference $exception) {
            Assert::same($exception->getMessage(), 'Only same-document JSON Pointer references are supported, got "shared.json#/A"');
        }
    }

    private function entryWithParameterSchemaRef(string $reference): string
    {
        return json_encode([
            'openapi' => '3.1.0',
            'paths' => ['/items' => ['get' => [
                'operationId' => 'listItems',
                'parameters' => [['name' => 'q', 'in' => 'query', 'schema' => ['$ref' => $reference]]],
                'responses' => ['200' => ['description' => 'ok']],
            ]]],
        ], JSON_THROW_ON_ERROR);
    }

    private function writePetTree(string $root): void
    {
        $this->write($root, 'entry.json', json_encode([
            'openapi' => '3.1.0',
            'paths' => ['/pets/{petId}' => ['get' => [
                'operationId' => 'getPet',
                'parameters' => [['$ref' => './components/parameters.json#/PetId']],
                'responses' => ['200' => ['$ref' => 'components/responses.yaml#/PetResponse']],
            ]]],
        ], JSON_THROW_ON_ERROR));
        $this->write($root, 'components/parameters.json', json_encode([
            'PetId' => ['name' => 'petId', 'in' => 'path', 'required' => true, 'schema' => ['$ref' => 'schemas.json#/Id']],
        ], JSON_THROW_ON_ERROR));
        $this->write($root, 'components/schemas.json', json_encode([
            'Id' => ['type' => 'integer'],
            'Pet' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['$ref' => '#/Id']]],
        ], JSON_THROW_ON_ERROR));
        $this->write($root, 'components/responses.yaml', <<<'YAML'
            PetResponse:
              description: ok
              content:
                application/json:
                  schema:
                    $ref: './schemas.json#/Pet'
            YAML);
    }

    private function workspace(): string
    {
        $directory = sys_get_temp_dir() . '/openapi-graph-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0o700, recursive: true)) {
            throw new \RuntimeException('Unable to create a workspace directory');
        }

        return $directory;
    }

    private function write(string $root, string $relative, string $contents): void
    {
        $path = $root . '/' . $relative;
        $directory = \dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o700, recursive: true)) {
            throw new \RuntimeException('Unable to create a fixture directory');
        }
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('Unable to write a fixture file');
        }
    }

    private function remove(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
