<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedReference;
use Rasuvaeff\OpenApiContract\Internal\Reference\JsonPointerResolver;
use Rasuvaeff\OpenApiContract\SchemaDialect;
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

    /**
     * Outside a schema — a Path Item, a Response, a Parameter — nothing can
     * evaluate a reference lazily, so a cycle there is refused as what it is,
     * whatever the depth budget: the budget is for the chain that never
     * comes back.
     */
    public function rejectsACycleOutsideASchemaAsCircular(): void
    {
        $resolver = new JsonPointerResolver(
            document: ['components' => ['responses' => ['loop' => ['$ref' => '#/components/responses/loop']]]],
            maximumReferenceDepth: 200,
        );

        try {
            $resolver->resolve(['$ref' => '#/components/responses/loop']);
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), 'Circular $ref "#/components/responses/loop" outside a schema in OpenAPI document');

            return;
        }

        Assert::true(actual: false, message: 'Expected a circular reference exception');
    }

    public function rejectsAChainThatOutrunsTheDepthBudget(): void
    {
        $document = [];
        foreach (range(0, 3) as $i) {
            $document['s' . $i] = ['$ref' => '#/s' . ($i + 1)];
        }
        $document['s4'] = ['type' => 'string'];
        $resolver = new JsonPointerResolver(document: $document, maximumReferenceDepth: 3);

        try {
            $resolver->resolve(['$ref' => '#/s0']);
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI $ref chain is too deep');

            return;
        }

        Assert::true(actual: false, message: 'Expected reference depth exception');
    }

    /**
     * A tree is a schema whose member is the schema itself. Inlining cannot
     * write that down; the backend can evaluate it as a `$defs` member the
     * back-reference points to, so that is the compiled form — the root
     * inlined as every schema is, and kept as a def as well.
     */
    public function compilesARecursiveSchemaToDefsAndALocalRef(): void
    {
        $resolver = new JsonPointerResolver(document: ['components' => ['schemas' => ['Node' => [
            'type' => 'object',
            'properties' => ['children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Node']]],
        ]]]]);

        $resolved = $resolver->resolve(['$ref' => '#/components/schemas/Node'], inSchema: true);

        $body = [
            'type' => 'object',
            'properties' => ['children' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/components.schemas.Node', 'type' => 'object']]],
        ];
        Assert::same($resolved, [...$body, '$defs' => ['components.schemas.Node' => $body]]);
    }

    /**
     * The cycle is found by the resolution path, not by a seen-set: a
     * component reached twice along different branches is not a cycle. At
     * the level the wire decoders read — a property schema, two levels
     * below the root — the second use keeps being inlined as the first was:
     * the decoder reads `properties` and `items` maps there, and a
     * deferred node carries only `type` and `format`. Deeper uses of the
     * same component do defer; that is the test below this one.
     */
    public function inlinesADiamondWithoutDefs(): void
    {
        $resolver = new JsonPointerResolver(document: ['components' => ['schemas' => [
            'Address' => ['type' => 'string'],
            'User' => ['type' => 'object', 'properties' => ['home' => ['$ref' => '#/components/schemas/Address'], 'work' => ['$ref' => '#/components/schemas/Address']]],
        ]]]);

        Assert::same(
            $resolver->resolve(['$ref' => '#/components/schemas/User'], inSchema: true),
            ['type' => 'object', 'properties' => ['home' => ['type' => 'string'], 'work' => ['type' => 'string']]],
        );
    }

    /**
     * A component reached twice below the decoder horizon — three levels
     * down, where only `type` and `format` are ever read off a node — is
     * kept as one `$defs` member and referenced from the second use, while
     * the first stays inlined. This is the shape a shared-component DAG
     * compiles to: Stripe's spec3.json inlines each large component once
     * per path that reaches it and multiplies along the depth (#161).
     */
    public function defersAComponentReachedTwiceBelowTheDecoderHorizon(): void
    {
        $resolver = new JsonPointerResolver(document: ['components' => ['schemas' => [
            'Address' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            'Parcel' => ['type' => 'object', 'properties' => [
                'sendTo' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Address']],
                'billTo' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Address']],
            ]],
        ]]]);

        $resolved = $resolver->resolve(['$ref' => '#/components/schemas/Parcel'], inSchema: true);

        $address = ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]];
        Assert::same($resolved['properties']['sendTo']['items'], $address);
        Assert::same(
            $resolved['properties']['billTo']['items'],
            ['$ref' => '#/$defs/components.schemas.Address', 'type' => 'object'],
        );
        Assert::same($resolved['$defs'], ['components.schemas.Address' => $address]);
    }

    /**
     * `format` rides a deferred node beside `type`: a multipart part's
     * default content type is chosen by it, and it is the target's own —
     * carrying it asserts nothing the def it names does not assert.
     */
    public function carriesFormatOnADeferredSharedComponent(): void
    {
        $resolver = new JsonPointerResolver(document: ['components' => ['schemas' => [
            'Blob' => ['type' => 'string', 'format' => 'binary'],
            'Upload' => ['type' => 'object', 'properties' => [
                'first' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Blob']],
                'second' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Blob']],
            ]],
        ]]]);

        $resolved = $resolver->resolve(['$ref' => '#/components/schemas/Upload'], inSchema: true);

        Assert::same(
            $resolved['properties']['second']['items'],
            ['$ref' => '#/$defs/components.schemas.Blob', 'type' => 'string', 'format' => 'binary'],
        );
    }

    /**
     * A second use above the decoder horizon is not resolved again: the
     * first resolution is reused as the inline it produced. The budget here
     * admits the walk with the reuse and refuses the same walk with a full
     * second resolution of `a` — pinning that the memo, not the depth
     * guard, is what keeps the protected level from doubling the work.
     */
    public function reusesTheFirstResolutionForAProtectedSecondUse(): void
    {
        $document = [
            'a' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
            'r' => ['type' => 'object', 'properties' => [
                'p1' => ['$ref' => '#/a'],
                'p2' => ['$ref' => '#/a'],
            ]],
        ];
        $resolved = (new JsonPointerResolver(document: $document, maximumResolvedNodes: 8))
            ->resolve(['$ref' => '#/r'], inSchema: true);

        Assert::same(
            $resolved['properties']['p2'],
            ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
        );
        Assert::false(array_key_exists('$defs', $resolved));
    }

    /**
     * The reuse of a protected inline is a schema reference like the first
     * use was: under 3.1 its asserting siblings are a conjunction, and the
     * decoding keywords of the remembered resolution are lifted so the node
     * still reads as one schema to the wire decoders.
     */
    public function mergesSiblingsIntoAProtectedReuseAsIntoTheFirstUse(): void
    {
        $document = ['a' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]];
        $resolver = new JsonPointerResolver(document: $document);
        $node = ['$ref' => '#/a', 'minProperties' => 1];

        $first = $resolver->resolve(['properties' => ['p' => $node]], inSchema: true);
        Assert::same($first['properties']['p'], [
            'allOf' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
                ['minProperties' => 1],
            ],
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
        ]);

        $second = $resolver->resolve(['properties' => ['q' => $node]], inSchema: true);
        Assert::same($second['properties']['q'], $first['properties']['p']);
    }

    /**
     * What a reuse carries is the delta of the resolution it reuses — the
     * defs registered along it — and not the defs of the whole document:
     * a later Schema Object that reached a different component does not
     * inherit a def nothing in it references.
     */
    public function carriesOnlyTheDefsOfTheRememberedResolution(): void
    {
        $document = ['components' => ['schemas' => [
            'One' => ['type' => 'object', 'properties' => [
                'first' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Shared']],
                'again' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Shared']],
            ]],
            'Shared' => ['type' => 'object', 'properties' => ['k' => ['type' => 'string']]],
            'Two' => ['type' => 'object', 'properties' => [
                'later' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Shared']],
            ]],
        ]]];
        $resolver = new JsonPointerResolver(document: $document);

        $resolver->resolve(['$ref' => '#/components/schemas/One'], inSchema: true);
        $second = $resolver->resolve(['$ref' => '#/components/schemas/Two'], inSchema: true);

        Assert::same(array_keys($second['$defs']), ['components.schemas.Shared']);
    }

    /**
     * Two schemas that reach each other are one cycle with two members, and
     * a cycle met after a chain that has already spent the depth budget is
     * still a cycle: the check comes first.
     */
    public function compilesMutualRecursionAndACycleBeyondTheDepthBudget(): void
    {
        $resolver = new JsonPointerResolver(document: ['components' => ['schemas' => [
            'A' => ['type' => 'object', 'properties' => ['b' => ['$ref' => '#/components/schemas/B']]],
            'B' => ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/components/schemas/A']]],
        ]]], maximumReferenceDepth: 2);

        $resolved = $resolver->resolve(['$ref' => '#/components/schemas/A'], inSchema: true);

        Assert::same(array_keys($resolved['$defs']), ['components.schemas.A']);
        Assert::same($resolved['properties']['b']['properties']['a'], ['$ref' => '#/$defs/components.schemas.A', 'type' => 'object']);
    }

    public function keepsA31SiblingOfABackReferenceAsAConjunction(): void
    {
        $resolver = new JsonPointerResolver(document: ['components' => ['schemas' => ['Node' => [
            'type' => 'object',
            'properties' => ['parent' => ['$ref' => '#/components/schemas/Node', 'description' => 'up', 'maxProperties' => 3]],
        ]]]]);

        $resolved = $resolver->resolve(['$ref' => '#/components/schemas/Node'], inSchema: true);

        Assert::same($resolved['properties']['parent'], [
            'allOf' => [['$ref' => '#/$defs/components.schemas.Node', 'type' => 'object'], ['maxProperties' => 3]],
            'type' => 'object',
            'description' => 'up',
        ]);
    }

    /**
     * The siblings of a 3.1 schema reference are resolved — a reference
     * among them is a reference — while under 3.0 they are ignored whole, a
     * broken reference among them included.
     */
    public function resolvesTheSiblingsOfAReferenceByDialect(): void
    {
        $document = ['a' => ['type' => 'object'], 'b' => ['type' => 'integer']];

        $resolved = (new JsonPointerResolver(document: $document))
            ->resolve(['$ref' => '#/a', 'properties' => ['n' => ['$ref' => '#/b']]], inSchema: true);
        Assert::same($resolved['allOf'][1], ['properties' => ['n' => ['type' => 'integer']]]);

        $ignored = (new JsonPointerResolver(document: $document, dialect: SchemaDialect::OpenApi30))
            ->resolve(['$ref' => '#/a', 'properties' => ['n' => ['$ref' => '#/missing']]], inSchema: true);
        Assert::same($ignored, ['type' => 'object']);
    }

    public function refusesACycleThatHoldsNoSchema(): void
    {
        $resolver = new JsonPointerResolver(document: ['components' => ['schemas' => [
            'A' => ['$ref' => '#/components/schemas/B'],
            'B' => ['$ref' => '#/components/schemas/A'],
        ]]]);

        try {
            $resolver->resolve(['$ref' => '#/components/schemas/A'], inSchema: true);
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI $ref "#/components/schemas/A" in OpenAPI document resolves to nothing but a reference to itself');

            return;
        }

        Assert::true(actual: false, message: 'Expected a self-reference exception');
    }

    /**
     * A compiled schema handed back for a second pass — the compiler resolves
     * the Path Item, then the Request Body inside it again — keeps the local
     * refs the first pass emitted, rather than looking them up in a document
     * that has no `$defs`.
     */
    public function leavesAnEmittedLocalDefsRefAlone(): void
    {
        $resolver = new JsonPointerResolver(document: []);
        $compiled = ['type' => 'array', 'items' => ['$ref' => '#/$defs/x'], '$defs' => ['x' => ['type' => 'string']]];

        Assert::same($resolver->resolve(['schema' => $compiled]), ['schema' => $compiled]);
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

        // The chain is resolved to its end before the merge, so an alias
        // lifts the same decoding keywords a direct reference does: the
        // parameter decoder reads `type` off the top either way.
        Assert::same($resolver->resolve(['$ref' => '#/a', 'additionalProperties' => false], inSchema: true), [
            'allOf' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
                ['additionalProperties' => false],
            ],
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
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
            'type' => 'object',
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
