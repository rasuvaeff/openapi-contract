<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Reference;

use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedReference;
use Rasuvaeff\OpenApiContract\InvalidContract;
use Rasuvaeff\OpenApiContract\SchemaDialect;

/**
 * Resolves same-document fragment references, and — when a DocumentGraph is
 * attached — relative file references inside the graph root, under explicit
 * shared work limits.
 *
 * @internal
 */
final class JsonPointerResolver
{
    /**
     * Keywords that assert nothing about an instance, plus the two OAS reads
     * directionally. They are lifted to the top of a merged Schema Object so a
     * `$ref` carrying assertion siblings still reads as one declaration to
     * everything that looks at a schema without evaluating it — the
     * directional rewrite, and the parameter decoder deciding whether a wire
     * value is a scalar, a list or an object.
     */
    private const array SCHEMA_ANNOTATIONS = [
        '$comment',
        'default',
        'deprecated',
        'description',
        'example',
        'examples',
        'externalDocs',
        'readOnly',
        'title',
        'writeOnly',
        'xml',
    ];

    /**
     * Assertions lifted from the *referenced* schema alongside them. Asserting
     * one of these twice asserts nothing new — the referenced schema is a
     * member of the conjunction already — but the decoder reads them off the
     * top of the node, and a value decoded as the wrong shape fails a check it
     * should have passed.
     */
    private const array SCHEMA_DECODING_KEYWORDS = ['type', 'items', 'properties'];

    /**
     * Keywords of a Schema Object whose value is the document author's data,
     * not a schema: `$ref` there is a member of a payload, and resolving it
     * either refuses a legal document or replaces the author's value with a
     * piece of the document.
     *
     * @var list<string>
     */
    private const array SCHEMA_DATA_KEYWORDS = ['const', 'default', 'enum', 'example', 'examples'];

    /**
     * The same, outside a Schema Object: the `example` a Parameter, Header or
     * Media Type Object declares, and the `value` of an Example Object. The
     * Example Object itself is still resolved — `examples: {sample: {$ref:
     * '#/components/examples/Sample'}}` is a reference and means one.
     *
     * @var list<string>
     */
    private const array DATA_KEYWORDS = ['example', 'value'];

    private int $resolvedNodes = 0;

    /**
     * The reference targets on the current resolution path, innermost last,
     * each mapped to whether a reference back to it was met below it — the
     * mark that turns an inlined schema into a `$defs` member as well.
     *
     * @var array<string, bool>
     */
    private array $path = [];

    /**
     * The `$defs` collected for the Schema Object being resolved, keyed by
     * def name; `null` outside a Schema Object. See {@see defer()}.
     *
     * @var array<string, array<array-key, mixed>|DeferredReference>|null
     */
    private ?array $defs = null;

    /**
     * @param array<string, mixed> $document
     */
    public function __construct(
        private readonly array $document,
        private readonly SchemaDialect $dialect = SchemaDialect::OpenApi31,
        private readonly int $maximumReferenceDepth = 32,
        private readonly int $maximumResolvedNodes = 100_000,
        private readonly ?DocumentGraph $graph = null,
    ) {
        if ($maximumReferenceDepth < 1) {
            throw new \InvalidArgumentException('Maximum reference depth must be positive');
        }
        if ($maximumResolvedNodes < 1) {
            throw new \InvalidArgumentException('Maximum resolved nodes must be positive');
        }
    }

    /**
     * @param array<array-key, mixed> $node
     * @param bool $inSchema whether the node sits in a Schema Object position;
     *        `$ref` means different things there and in a Reference Object one
     *
     * @return array<array-key, mixed>
     */
    public function resolve(array $node, int $referenceDepth = 0, bool $inSchema = false): array
    {
        $file = $this->graph?->entryPath() ?? '';
        $resolved = $inSchema
            ? $this->resolveSchema($node, $file, $referenceDepth)
            : $this->resolveIn($node, $file, $referenceDepth, inSchema: false);
        if ($resolved instanceof DeferredReference) {
            // Outside a schema nothing is deferred, and a schema root that
            // is one has been refused by resolveSchema() already.
            throw new \LogicException('A deferred reference escaped its schema');
        }

        return $resolved;
    }

    /**
     * Resolves one Schema Object root: the node a `schema` key introduces, or
     * the node {@see resolve()} was told is one. A schema is where a
     * reference may legally reach back to a schema still being resolved — a
     * tree's `children` are trees — and inlining cannot express that, so the
     * members of every cycle met below the root are collected as its `$defs`
     * and the back-references become local `$ref`s into them, the form the
     * validation backend evaluates natively. A Schema Object without a cycle
     * compiles exactly as before: no `$defs` appears.
     *
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private function resolveSchema(array $node, string $file, int $referenceDepth): array
    {
        $outer = $this->defs;
        $this->defs = [];

        try {
            $resolved = $this->resolveIn($node, $file, $referenceDepth, inSchema: true);
            $defs = $this->collectedDefs();
        } finally {
            $this->defs = $outer;
        }
        if ($resolved instanceof DeferredReference) {
            // `A: {$ref: B}`, `B: {$ref: A}` — a cycle with no schema in it
            // anywhere. Nothing to defer to, and nothing to evaluate.
            throw new InvalidContract(sprintf('OpenAPI $ref "%s" in %s resolves to nothing but a reference to itself', $resolved->reference, $this->label($file)));
        }
        if ($defs === []) {
            return $resolved;
        }
        /** @var array<array-key, mixed> $existing */
        $existing = is_array($resolved['$defs'] ?? null) ? $resolved['$defs'] : [];
        $resolved['$defs'] = [...$existing, ...$defs];

        return $this->materialize($resolved);
    }

    /**
     * The defs the schema being resolved has collected so far — read through
     * a method because the recursion below {@see resolveSchema()} fills the
     * property it just emptied.
     *
     * @return array<string, array<array-key, mixed>|DeferredReference>
     */
    private function collectedDefs(): array
    {
        return $this->defs ?? [];
    }

    /** Whether a reference back to the target was met while it was on the path. */
    private function wasReferencedBelow(string $target): bool
    {
        return ($this->path[$target] ?? false) === true;
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>|DeferredReference
     */
    private function resolveIn(array $node, string $file, int $referenceDepth, bool $inSchema): array|DeferredReference
    {
        if (++$this->resolvedNodes > $this->maximumResolvedNodes) {
            throw new InvalidContract(sprintf(
                'OpenAPI document exceeds the reference-resolution budget of %d nodes',
                $this->maximumResolvedNodes,
            ));
        }

        if (!array_key_exists('$ref', $node)) {
            return $this->resolveMembers($node, $file, $referenceDepth, $inSchema);
        }
        if ($inSchema && is_string($node['$ref']) && str_starts_with($node['$ref'], '#/$defs/')) {
            // A local reference into the schema's own `$defs` is what this
            // resolver emits for a cycle, and what the backend resolves; a
            // compiled schema handed back for a second pass keeps it. No
            // OpenAPI document has a `$defs` at its root for one to reach.
            return $this->resolveMembers($node, $file, $referenceDepth, $inSchema);
        }

        [$targetFile, $fragment, $reference] = $this->target($node['$ref'], $file);
        $target = $targetFile . $fragment;
        $siblings = $node;
        unset($siblings['$ref']);
        if ($siblings !== [] && $this->dialect !== SchemaDialect::OpenApi30) {
            // Under 3.0 the siblings are ignored whole, references inside
            // them included; under 3.1 they are kept, so they are resolved.
            $siblings = $this->resolveMembers($siblings, $file, $referenceDepth, $inSchema);
        }

        if (array_key_exists($target, $this->path)) {
            // Seen before the depth is charged: a cycle is a shape, not a
            // runaway, and the depth budget is for the chain that never
            // comes back.
            if (!$inSchema) {
                // A Path Item, Response or Parameter that refers back to
                // itself has no meaning the object model can carry, and
                // nothing downstream could evaluate it lazily.
                throw new InvalidContract(sprintf('Circular $ref "%s" outside a schema in %s', $reference, $this->label($file)));
            }
            $this->path[$target] = true;

            return $this->merge($siblings, $this->defer($reference, $targetFile, $fragment, $file), $inSchema);
        }
        if (++$referenceDepth > $this->maximumReferenceDepth) {
            throw new InvalidContract('OpenAPI $ref chain is too deep');
        }

        $this->path[$target] = false;

        try {
            $resolved = $this->resolveIn($this->lookup($targetFile, $fragment, $reference, $file), $targetFile, $referenceDepth, $inSchema);
            $referencedBelow = $this->wasReferencedBelow($target);
        } finally {
            unset($this->path[$target]);
        }
        if ($referencedBelow && $this->defs !== null && is_array($resolved)) {
            // Inlined where it was first met, as every schema is, and kept as
            // a def as well for the references that reached back to it.
            $this->defs[$this->defName($targetFile, $fragment)] = $resolved;
        }

        return $this->merge($siblings, $resolved, $inSchema);
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private function resolveMembers(array $node, string $file, int $referenceDepth, bool $inSchema): array
    {
        /** @var mixed $value */
        foreach ($node as $key => $value) {
            if (!is_array($value) || $this->isData($key, $inSchema)) {
                continue;
            }
            // A Schema Object is entered through a `schema` key and never
            // left: everything below one is a schema too.
            $node[$key] = !$inSchema && $key === 'schema'
                ? $this->resolveSchema($value, $file, $referenceDepth)
                : $this->resolveIn($value, $file, $referenceDepth, $inSchema);
        }

        return $node;
    }

    /**
     * A reference back to a schema on the resolution path: what stands in
     * its place until the Schema Object root is done, when
     * {@see materialize()} turns it into `{$ref: '#/$defs/<name>'}`. The
     * target's `type` is read now, unresolved, and carried on the node, so
     * the wire decoders can still tell a list from a scalar off the top of
     * it; `items` and `properties` are not, because unresolved they would
     * bring the document's own `$ref`s into a compiled schema. Nothing is
     * lost by that: a deferred node only ever sits below a schema root, and
     * the decoders read `properties` and `items` off the root alone — a
     * `deepObject` has one level of members, a form or multipart body one
     * level of parts — while the backend, which follows the reference,
     * judges the whole value.
     */
    private function defer(string $reference, string $targetFile, string $fragment, string $file): DeferredReference
    {
        $body = $this->lookup($targetFile, $fragment, $reference, $file);

        return new DeferredReference(
            $reference,
            $this->defName($targetFile, $fragment),
            array_intersect_key($body, ['type' => null]),
        );
    }

    /**
     * Replaces every {@see DeferredReference} below the node — the `$defs`
     * members included, since a cycle's back-reference lives inside one —
     * with the local `$ref` it stands for.
     *
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private function materialize(array $node): array
    {
        /** @var mixed $value */
        foreach ($node as $key => $value) {
            if ($value instanceof DeferredReference) {
                $node[$key] = $value->toSchema();
            } elseif (is_array($value)) {
                $node[$key] = $this->materialize($value);
            }
        }

        return $node;
    }

    /**
     * The `$defs` name of a target: its JSON Pointer with the `/` separators
     * spelled as `.` (`#/components/schemas/Node` → `components.schemas.Node`),
     * prefixed by the file it lives in and a `:` when that is not the entry
     * document (`a.json:Node`). Deterministic, and the same name wherever
     * the target is reached from, so two cycles through one schema share a
     * single def. The name is what a consumer sees on the compiled
     * operation; `#` is kept out of it because the local `$ref` that names
     * it is a URI fragment.
     */
    private function defName(string $targetFile, string $fragment): string
    {
        $name = str_replace('/', '.', substr($fragment, 2));
        if ($this->graph instanceof DocumentGraph && $targetFile !== $this->graph->entryPath()) {
            $name = $this->graph->displayPath($targetFile) . ':' . $name;
        }

        return $name === '' ? 'document' : $name;
    }

    /**
     * What a node with a `$ref` means once the reference is resolved. The
     * answer differs along two axes, and reading every node by the 3.1 Schema
     * Object rule made the other three wrong in the fail-open direction — a
     * sibling `type` replaced the referenced constraint instead of being
     * ignored or applied on top of it.
     *
     * | | 3.0 | 3.1 |
     * |---|---|---|
     * | Reference Object | siblings ignored | siblings ignored, `summary`/`description` override |
     * | Schema Object | siblings ignored (JSON Reference) | siblings apply *in addition* (2020-12 applicator) |
     *
     * The 3.1 Schema Object case is a conjunction, not an override, so it
     * compiles to `allOf` — with the referenced schema whole in one member and
     * the asserting siblings in the other. Annotations and the keywords the
     * decoder reads are lifted to the top so the node still looks like one
     * schema to everything that inspects it without evaluating it.
     *
     * @param array<array-key, mixed> $siblings the node without its `$ref`, members resolved
     * @param array<array-key, mixed>|DeferredReference $resolved
     *
     * @return array<array-key, mixed>|DeferredReference
     */
    private function merge(array $siblings, array|DeferredReference $resolved, bool $inSchema): array|DeferredReference
    {
        if ($this->dialect === SchemaDialect::OpenApi30 || $siblings === []) {
            // OAS 3.0.4: "This object cannot be extended with additional
            // properties, and any properties added SHALL be ignored" — and a
            // 3.0 Schema Object holds a Reference Object, not a 2020-12 schema.
            return $resolved;
        }
        if (!$inSchema) {
            if ($resolved instanceof DeferredReference) {
                throw new \LogicException('Only a schema reference is deferred');
            }

            // Both are carried wherever a Reference Object appears, including
            // the types that have no `summary` field of their own — the
            // specification says the override "has no effect" there, and it
            // has none here either: nothing downstream reads the key, and
            // telling those types apart would mean teaching the resolver the
            // whole object model to change nothing.
            return [...$resolved, ...array_intersect_key($siblings, ['summary' => null, 'description' => null])];
        }
        $annotations = array_intersect_key($siblings, array_flip(self::SCHEMA_ANNOTATIONS));
        $assertions = array_diff_key($siblings, $annotations);
        if ($resolved instanceof DeferredReference) {
            // Only a schema defers. The body is still being resolved, so
            // nothing can be lifted off it but the `type` the deferred node
            // already carries; the siblings are a conjunction with it as
            // with any other 3.1 schema reference.
            return $assertions === []
                ? new DeferredReference($resolved->reference, $resolved->name, [...$resolved->shape, ...$annotations])
                : ['allOf' => [$resolved, $assertions], ...$resolved->shape, ...$annotations];
        }
        if ($assertions === []) {
            return [...$resolved, ...$annotations];
        }

        return [
            'allOf' => [$resolved, $assertions],
            ...array_intersect_key($resolved, array_flip([...self::SCHEMA_ANNOTATIONS, ...self::SCHEMA_DECODING_KEYWORDS])),
            ...$annotations,
        ];
    }

    /**
     * Whether a member holds data rather than document structure, and so is
     * left exactly as the author wrote it.
     *
     * A specification extension is included wherever it appears: no
     * specification gives `$ref` a meaning inside one, and reading a vendor's
     * payload as a reference refused documents that merely showed one.
     */
    private function isData(int|string $key, bool $inSchema): bool
    {
        if (!is_string($key)) {
            return false;
        }
        if (str_starts_with($key, 'x-')) {
            return true;
        }

        return in_array($key, $inSchema ? self::SCHEMA_DATA_KEYWORDS : self::DATA_KEYWORDS, strict: true);
    }

    /**
     * @return array{string, string, string} target file, fragment, reference
     */
    private function target(mixed $reference, string $file): array
    {
        if (!is_string($reference)) {
            throw UnsupportedReference::forValue($reference);
        }
        if (str_starts_with($reference, '#')) {
            return [$file, $reference, $reference];
        }
        if (!$this->graph instanceof DocumentGraph) {
            throw UnsupportedReference::forValue($reference);
        }
        $position = strpos($reference, '#');
        $filePart = $position === false ? $reference : substr($reference, 0, $position);
        $fragment = $position === false ? '#' : substr($reference, $position);

        return [$this->graph->resolveTarget($file, $filePart, $reference), $fragment, $reference];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function lookup(string $file, string $fragment, string $reference, string $sourceFile): array
    {
        if ($fragment === '#') {
            return $this->documentOf($file);
        }
        if (!str_starts_with($fragment, '#/')) {
            throw UnsupportedReference::forValue($reference);
        }

        $node = $this->documentOf($file);
        foreach (explode('/', substr($fragment, 2)) as $segment) {
            $segment = $this->decodeSegment($segment, $reference);
            if (!array_key_exists($segment, $node)) {
                throw new InvalidContract(sprintf('Unresolvable $ref "%s" in %s', $reference, $this->label($sourceFile)));
            }
            if (!is_array($node[$segment])) {
                throw new InvalidContract(sprintf('$ref "%s" in %s must point to an object', $reference, $this->label($sourceFile)));
            }

            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function documentOf(string $file): array
    {
        if ($this->graph instanceof DocumentGraph && $file !== '') {
            return $this->graph->document($file);
        }

        return $this->document;
    }

    private function label(string $sourceFile): string
    {
        if ($this->graph instanceof DocumentGraph && $sourceFile !== '') {
            return sprintf('OpenAPI document "%s"', $this->graph->displayPath($sourceFile));
        }

        return 'OpenAPI document';
    }

    private function decodeSegment(string $segment, string $reference): string
    {
        if (preg_match('/~(?:[^01]|$)/', $segment) === 1) {
            throw new InvalidContract(sprintf('Invalid JSON Pointer escape in $ref "%s"', $reference));
        }

        return str_replace(['~1', '~0'], ['/', '~'], $segment);
    }
}
