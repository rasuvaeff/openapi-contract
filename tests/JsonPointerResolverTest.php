<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedReference;
use Rasuvaeff\OpenApiContract\Internal\Reference\JsonPointerResolver;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaDialect;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(JsonPointerResolver::class)]
#[Covers(UnsupportedReference::class)]
final class JsonPointerResolverTest
{
    public function resolvesEscapedSameDocumentReferences(): void
    {
        $resolver = new JsonPointerResolver([
            'components' => [
                'schemas' => [
                    'name/with~characters' => ['type' => 'string'],
                ],
            ],
        ]);

        Assert::same(
            $resolver->resolve(['$ref' => '#/components/schemas/name~1with~0characters']),
            ['type' => 'string'],
        );
    }

    #[DataProvider('unsupportedReferenceProvider')]
    public function rejectsReferencesOutsideTheLocalFragmentBoundary(mixed $reference): void
    {
        try {
            (new JsonPointerResolver([]))->resolve(['$ref' => $reference]);
        } catch (UnsupportedReference $exception) {
            Assert::string($exception->getMessage())->contains('same-document JSON Pointer');

            return;
        }

        Assert::true(actual: false, message: 'Expected unsupported reference exception');
    }

    /** @return iterable<string, array{mixed}> */
    public static function unsupportedReferenceProvider(): iterable
    {
        yield 'remote URL' => ['https://example.test/schema.json'];
        yield 'local file' => ['schema.json#/value'];
        yield 'non-string reference' => [42];
    }

    public function rejectsCircularReferencesAtTheConfiguredDepth(): void
    {
        $resolver = new JsonPointerResolver(
            document: ['components' => ['schemas' => ['loop' => ['$ref' => '#/components/schemas/loop']]]],
            maximumReferenceDepth: 2,
        );

        try {
            $resolver->resolve(['$ref' => '#/components/schemas/loop']);
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI $ref chain is too deep (possible circular reference)');

            return;
        }

        Assert::true(actual: false, message: 'Expected reference depth exception');
    }

    public function rejectsDocumentsThatExhaustTheSharedNodeBudget(): void
    {
        $resolver = new JsonPointerResolver(document: [], maximumResolvedNodes: 2);

        try {
            $resolver->resolve(['first' => ['second' => []]]);
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI document exceeds the reference-resolution budget of 2 nodes');

            return;
        }

        Assert::true(actual: false, message: 'Expected reference-resolution budget exception');
    }

    public function honorsExactBudgetBoundaries(): void
    {
        $flat = new JsonPointerResolver(document: [], maximumReferenceDepth: 1, maximumResolvedNodes: 1);
        Assert::same($flat->resolve(['x' => 1]), ['x' => 1]);

        $single = new JsonPointerResolver(document: ['a' => ['x' => 1]], maximumReferenceDepth: 1);
        Assert::same($single->resolve(['$ref' => '#/a']), ['x' => 1]);
    }

    public function returnsEveryKeyOfResolvedNodes(): void
    {
        $resolver = new JsonPointerResolver(document: ['t' => ['p' => 1, 'q' => 2]]);

        Assert::same($resolver->resolve(['$ref' => '#/t']), ['p' => 1, 'q' => 2]);
        Assert::same($resolver->resolve(['m' => 1, 'n' => 2]), ['m' => 1, 'n' => 2]);
    }

    public function ignoresSiblingsOfAReferenceUnderOpenApi30(): void
    {
        // OAS 3.0.4, Reference Object: "This object cannot be extended with
        // additional properties, and any properties added SHALL be ignored" —
        // and a 3.0 Schema Object holds a Reference Object, not a 2020-12
        // schema, so the rule is the same in both positions.
        $resolver = new JsonPointerResolver(
            document: ['a' => ['type' => 'string', 'description' => 'referenced']],
            dialect: SchemaDialect::OpenApi30,
        );
        $node = ['$ref' => '#/a', 'type' => 'integer', 'description' => 'mine'];

        Assert::same($resolver->resolve($node), ['type' => 'string', 'description' => 'referenced']);
        Assert::same($resolver->resolve($node, inSchema: true), ['type' => 'string', 'description' => 'referenced']);
    }

    public function keepsOnlySummaryAndDescriptionOnA31ReferenceObject(): void
    {
        $resolver = new JsonPointerResolver(document: ['a' => ['description' => 'referenced', 'required' => true]]);

        Assert::same(
            $resolver->resolve(['$ref' => '#/a', 'description' => 'mine', 'summary' => 'short', 'required' => false]),
            ['description' => 'mine', 'required' => true, 'summary' => 'short'],
        );
    }

    public function appliesSchemaSiblingsInAdditionUnderOpenApi31(): void
    {
        $resolver = new JsonPointerResolver(document: ['a' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'readOnly' => true,
        ]]);

        // Annotations alone read as one schema; nothing is asserted twice.
        Assert::same(
            $resolver->resolve(['$ref' => '#/a', 'title' => 'mine'], inSchema: true),
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'readOnly' => true, 'title' => 'mine'],
        );

        // An asserting sibling is a conjunction, not an override: 2020-12
        // makes `$ref` an applicator, so both constrain the instance.
        $conjunction = $resolver->resolve(['$ref' => '#/a', 'additionalProperties' => false, 'title' => 'mine'], inSchema: true);
        Assert::same($conjunction['allOf'], [
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'readOnly' => true],
            ['additionalProperties' => false],
        ]);
        // Lifted so the node still reads as one schema to the directional
        // rewrite and to the parameter decoder.
        Assert::same($conjunction['type'], 'object');
        Assert::same($conjunction['properties'], ['id' => ['type' => 'integer']]);
        Assert::true($conjunction['readOnly']);
        Assert::same($conjunction['title'], 'mine');
        Assert::false(array_key_exists('$ref', $conjunction));
    }

    public function keepsAChainedReferenceInsideTheConjunction(): void
    {
        $resolver = new JsonPointerResolver(document: [
            'a' => ['$ref' => '#/b'],
            'b' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        ]);

        // Only what the merged node needs at the top is lifted. Lifting the
        // reference too would restart the merge from a node that already
        // carries the conjunction, and nest it inside itself.
        Assert::same($resolver->resolve(['$ref' => '#/a', 'additionalProperties' => false], inSchema: true), [
            'allOf' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
                ['additionalProperties' => false],
            ],
        ]);

        // A reference that carries an asserting sibling of its own is a
        // conjunction in its own right, and stays one inside this member.
        $nested = new JsonPointerResolver(document: [
            'a' => ['$ref' => '#/b', 'minProperties' => 1],
            'b' => ['type' => 'object'],
        ]);
        Assert::same($nested->resolve(['$ref' => '#/a', 'additionalProperties' => false], inSchema: true), [
            'allOf' => [
                ['allOf' => [['type' => 'object'], ['minProperties' => 1]], 'type' => 'object'],
                ['additionalProperties' => false],
            ],
        ]);
    }

    public function entersSchemaModeThroughASchemaKeyOnly(): void
    {
        $resolver = new JsonPointerResolver(document: [
            's' => ['type' => 'integer'],
            'p' => ['name' => 'id', 'in' => 'query', 'required' => true],
        ]);

        Assert::same(
            $resolver->resolve(['content' => ['application/json' => ['schema' => ['$ref' => '#/s', 'maximum' => 10]]]]),
            ['content' => ['application/json' => ['schema' => [
                'allOf' => [['type' => 'integer'], ['maximum' => 10]],
                'type' => 'integer',
            ]]]],
        );

        // Everywhere else the node is a Reference Object, whose added
        // properties the specification says SHALL be ignored — reading one as
        // a Schema Object here would quietly make this parameter optional.
        Assert::same(
            $resolver->resolve(['parameters' => [['$ref' => '#/p', 'required' => false]]]),
            ['parameters' => [['name' => 'id', 'in' => 'query', 'required' => true]]],
        );

        // Schema mode is entered once and never left: a subschema is reached
        // through keys of its own, none of them spelled `schema`, and at every
        // depth below the one that entered it.
        $conjunction = ['allOf' => [['type' => 'integer'], ['maximum' => 10]], 'type' => 'integer'];
        Assert::same(
            $resolver->resolve(['schema' => ['type' => 'array', 'items' => ['$ref' => '#/s', 'maximum' => 10]]]),
            ['schema' => ['type' => 'array', 'items' => $conjunction]],
        );
        Assert::same(
            $resolver->resolve(['schema' => ['type' => 'object', 'properties' => ['n' => ['$ref' => '#/s', 'maximum' => 10]]]]),
            ['schema' => ['type' => 'object', 'properties' => ['n' => $conjunction]]],
        );
    }

    /**
     * `$ref` is a keyword only where the specification puts one. Which
     * members hold data depends on the position: a Schema Object's `default`
     * is data, the same key elsewhere is not; an Example Object's `value` is
     * data, the same key inside a schema is not a keyword at all.
     */
    #[DataProvider('dataPositionProvider')]
    public function leavesDataMembersUnresolved(array $node, bool $inSchema, array $expected): void
    {
        $resolver = new JsonPointerResolver(document: ['a' => ['type' => 'string']]);

        Assert::same($resolver->resolve($node, inSchema: $inSchema), $expected);
    }

    /** @return iterable<string, array{array<array-key, mixed>, bool, array<array-key, mixed>}> */
    public static function dataPositionProvider(): iterable
    {
        $pointer = ['$ref' => '#/a'];
        $target = ['type' => 'string'];

        yield 'example outside a schema' => [['example' => $pointer], false, ['example' => $pointer]];
        yield 'example inside a schema' => [['example' => $pointer], true, ['example' => $pointer]];
        yield 'value outside a schema' => [['value' => $pointer], false, ['value' => $pointer]];
        // `value` is not a schema keyword, so inside a schema it is structure.
        yield 'value inside a schema' => [['value' => $pointer], true, ['value' => $target]];
        yield 'default inside a schema' => [['default' => $pointer], true, ['default' => $pointer]];
        // `default` outside a schema is not a data keyword either.
        yield 'default outside a schema' => [['default' => $pointer], false, ['default' => $target]];
        yield 'enum inside a schema' => [['enum' => [$pointer]], true, ['enum' => [$pointer]]];
        yield 'const inside a schema' => [['const' => $pointer], true, ['const' => $pointer]];
        yield 'extension outside a schema' => [['x-vendor' => $pointer], false, ['x-vendor' => $pointer]];
        yield 'extension inside a schema' => [['x-vendor' => $pointer], true, ['x-vendor' => $pointer]];
        // Structure is still resolved on both sides of the fence.
        yield 'a schema member is structure' => [['schema' => $pointer], false, ['schema' => $target]];
        yield 'an examples map is structure' => [['examples' => ['s' => $pointer]], false, ['examples' => ['s' => $target]]];
    }

    public function reportsTheUnsupportedReferenceValue(): void
    {
        try {
            (new JsonPointerResolver(document: ['a' => ['x' => 1]]))->resolve(['$ref' => 'a/x']);
            Assert::true(actual: false, message: 'Expected unsupported reference exception');
        } catch (UnsupportedReference $exception) {
            Assert::same($exception->getMessage(), 'Only same-document JSON Pointer references are supported, got "a/x"');
        }
    }
}
