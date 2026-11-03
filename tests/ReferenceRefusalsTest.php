<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Internal\Compilation\DocumentNodes;
use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedReference;
use Rasuvaeff\OpenApiContract\Internal\Reference\DeferredReference;
use Rasuvaeff\OpenApiContract\Internal\Reference\DocumentGraph;
use Rasuvaeff\OpenApiContract\Internal\Reference\JsonPointerResolver;
use Rasuvaeff\OpenApiContract\Internal\Response\ResponseSelector;
use Rasuvaeff\OpenApiContract\InvalidContract;
use Rasuvaeff\OpenApiContract\Tests\Support\Refusal;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(JsonPointerResolver::class)]
#[Covers(DeferredReference::class)]
#[Covers(DocumentGraph::class)]
#[Covers(DocumentNodes::class)]
#[Covers(ResponseSelector::class)]
#[Covers(UnsupportedReference::class)]
final class ReferenceRefusalsTest
{
    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $node
     */
    #[DataProvider('refusedReferenceProvider')]
    public function refusesAReferenceItCannotFollow(array $document, array $node, string $class, string $message): void
    {
        Assert::same(Refusal::of(static fn() => (new JsonPointerResolver($document))->resolve($node)), [$class, $message]);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, class-string<\Throwable>, string}> */
    public static function refusedReferenceProvider(): iterable
    {
        yield 'a fragment that is not a pointer' => [[], ['$ref' => '#foo'], UnsupportedReference::class, 'Only same-document JSON Pointer references are supported, got "#foo"'];
        yield 'a pointer to a scalar' => [['x' => 'text'], ['$ref' => '#/x'], InvalidContract::class, '$ref "#/x" in OpenAPI document must point to an object'];
        yield 'a pointer to nothing' => [['x' => []], ['$ref' => '#/y'], InvalidContract::class, 'Unresolvable $ref "#/y" in OpenAPI document'];
        yield 'an unknown escape' => [['a' => ['b' => []]], ['$ref' => '#/a~2b'], InvalidContract::class, 'Invalid JSON Pointer escape in $ref "#/a~2b"'];
        yield 'a trailing tilde' => [['a' => []], ['$ref' => '#/a~'], InvalidContract::class, 'Invalid JSON Pointer escape in $ref "#/a~"'];
    }

    public function acceptsTheSmallestBudgets(): void
    {
        $resolver = new JsonPointerResolver(['x' => ['type' => 'string']], maximumReferenceDepth: 1, maximumResolvedNodes: 2);

        Assert::same($resolver->resolve(['$ref' => '#/x']), ['type' => 'string']);
    }

    #[DataProvider('invalidBudgetProvider')]
    public function refusesABudgetThatIsNotPositive(int $depth, int $nodes, string $message): void
    {
        Assert::same(
            Refusal::of(static fn() => new JsonPointerResolver([], maximumReferenceDepth: $depth, maximumResolvedNodes: $nodes)),
            [\InvalidArgumentException::class, $message],
        );
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function invalidBudgetProvider(): iterable
    {
        yield 'depth zero' => [0, 1, 'Maximum reference depth must be positive'];
        yield 'depth negative' => [-1, 1, 'Maximum reference depth must be positive'];
        yield 'nodes zero' => [1, 0, 'Maximum resolved nodes must be positive'];
        yield 'nodes negative' => [1, -5, 'Maximum resolved nodes must be positive'];
    }

    public function resolvesWithTheDefaultsOfAFreshCall(): void
    {
        $resolver = new JsonPointerResolver(['x' => ['y' => ['type' => 'string']]]);

        Assert::same($resolver->resolve(['a' => ['$ref' => '#/x/y']]), ['a' => ['type' => 'string']]);
        Assert::same($resolver->resolve(['schema' => ['$ref' => '#/x/y']], referenceDepth: 0, inSchema: false), ['schema' => ['type' => 'string']]);
    }

    public function namesADeferredDefinitionWithItsPointerEscapes(): void
    {
        $reference = new DeferredReference('#/a', 'pets.json:components.schemas.a~b/c', ['type' => 'string']);

        Assert::same($reference->toSchema(), ['$ref' => '#/$defs/pets.json:components.schemas.a~0b~1c', 'type' => 'string']);
    }

    public function refusesANodeBudgetThatIsNotPositive(): void
    {
        Assert::same(
            Refusal::of(static fn() => DocumentGraph::open('irrelevant.json', maximumNodes: 0)),
            [\InvalidArgumentException::class, 'Maximum node budget must be positive'],
        );
        Assert::same(
            Refusal::of(static fn() => DocumentGraph::open('irrelevant.json', maximumNodes: -1)),
            [\InvalidArgumentException::class, 'Maximum node budget must be positive'],
        );
    }

    public function acceptsADocumentThatFillsTheNodeBudgetExactly(): void
    {
        $root = $this->workspace();

        try {
            file_put_contents($root . '/entry.json', '{"a":1}');

            $graph = DocumentGraph::open($root . '/entry.json', maximumNodes: 1);

            Assert::same($graph->entryDocument(), ['a' => 1]);
        } finally {
            $this->remove($root);
        }
    }

    public function chargesEveryFileAgainstOneSharedNodeBudget(): void
    {
        $root = $this->workspace();

        try {
            $first = '{"a":{"b":1,"c":2}}';
            $second = '{"d":{"e":3,"f":4}}';
            file_put_contents($root . '/entry.json', $first);
            file_put_contents($root . '/other.json', $second);
            $firstNodes = DocumentNodes::within(json_decode($first, associative: true, flags: JSON_THROW_ON_ERROR), PHP_INT_MAX);
            $secondNodes = DocumentNodes::within(json_decode($second, associative: true, flags: JSON_THROW_ON_ERROR), PHP_INT_MAX);

            $roomForBoth = DocumentGraph::open($root . '/entry.json', maximumNodes: (int) $firstNodes + (int) $secondNodes);
            Assert::same(count($roomForBoth->document((string) realpath($root . '/other.json'))), 1);

            $roomForOne = DocumentGraph::open($root . '/entry.json', maximumNodes: (int) $firstNodes + (int) $secondNodes - 1);
            Assert::same(
                Refusal::of(static fn() => $roomForOne->document((string) realpath($root . '/other.json'))),
                [InvalidContract::class, 'OpenAPI document "other.json" exceeds the shared node budget'],
            );
        } finally {
            $this->remove($root);
        }
    }

    public function refusesAYamlDocumentThatDoesNotParse(): void
    {
        $root = $this->workspace();

        try {
            file_put_contents($root . '/entry.yaml', "a: [1\nb: {");

            [$class, $message] = Refusal::of(static fn() => DocumentGraph::open($root . '/entry.yaml'));

            Assert::same($class, InvalidContract::class);
            Assert::same($message, 'OpenAPI YAML document "entry.yaml" is not valid YAML');
        } finally {
            $this->remove($root);
        }
    }

    public function refusesAYamlDocumentThatIsAList(): void
    {
        $root = $this->workspace();

        try {
            file_put_contents($root . '/entry.yml', "- a\n- b\n");

            Assert::same(
                Refusal::of(static fn() => DocumentGraph::open($root . '/entry.yml')),
                [InvalidContract::class, 'OpenAPI YAML document "entry.yml" must decode to an object'],
            );
        } finally {
            $this->remove($root);
        }
    }

    public function refusesAResponseDefinitionThatIsNotAnObject(): void
    {
        Assert::same(
            Refusal::of(static fn() => (new ResponseSelector())->select(['200' => 'text'], 200)),
            [InvalidContract::class, 'Response "200" must be an object'],
        );
        Assert::same(
            Refusal::of(static fn() => (new ResponseSelector())->select(['default' => 5], 500)),
            [InvalidContract::class, 'Response "default" must be an object'],
        );
    }

    public function skipsNumericKeysThatAreNotTheStatusWhenLookingForARange(): void
    {
        $selected = Refusal::quietly(static fn() => (new ResponseSelector())->select([500 => ['description' => 'x'], '2XX' => ['description' => 'y']], 200));

        Assert::same($selected?->key, '2XX');
    }

    private function workspace(): string
    {
        $directory = sys_get_temp_dir() . '/openapi-refusals-' . bin2hex(random_bytes(6));
        if (!mkdir($directory, 0o700, recursive: true)) {
            throw new \RuntimeException('Unable to create a workspace directory');
        }

        return $directory;
    }

    private function remove(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($directory . '/' . $entry);
            }
        }
        rmdir($directory);
    }
}
