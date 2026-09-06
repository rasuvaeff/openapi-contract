<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Reference;

use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedReference;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaDialect;
use Rasuvaeff\OpenApiContract\InvalidContract;

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

    private int $resolvedNodes = 0;

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
        return $this->resolveIn($node, $this->graph?->entryPath() ?? '', $referenceDepth, $inSchema);
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private function resolveIn(array $node, string $file, int $referenceDepth, bool $inSchema): array
    {
        if (++$this->resolvedNodes > $this->maximumResolvedNodes) {
            throw new InvalidContract(sprintf(
                'OpenAPI document exceeds the reference-resolution budget of %d nodes',
                $this->maximumResolvedNodes,
            ));
        }

        while (array_key_exists('$ref', $node)) {
            [$targetFile, $fragment, $reference] = $this->target($node['$ref'], $file);
            if (++$referenceDepth > $this->maximumReferenceDepth) {
                throw new InvalidContract('OpenAPI $ref chain is too deep (possible circular reference)');
            }

            $resolved = $this->lookup($targetFile, $fragment, $reference, $file);
            if ($targetFile !== $file) {
                $resolved = $this->resolveIn($resolved, $targetFile, $referenceDepth, $inSchema);
            }
            $node = $this->merge($node, $resolved, $inSchema);
        }

        /** @var mixed $value */
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                // A Schema Object is entered through a `schema` key and never
                // left: everything below one is a schema too.
                $node[$key] = $this->resolveIn($value, $file, $referenceDepth, $inSchema || $key === 'schema');
            }
        }

        return $node;
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
     * @param array<array-key, mixed> $node
     * @param array<array-key, mixed> $resolved
     *
     * @return array<array-key, mixed>
     */
    private function merge(array $node, array $resolved, bool $inSchema): array
    {
        $siblings = $node;
        unset($siblings['$ref']);
        if ($this->dialect === SchemaDialect::OpenApi30 || $siblings === []) {
            // OAS 3.0.4: "This object cannot be extended with additional
            // properties, and any properties added SHALL be ignored" — and a
            // 3.0 Schema Object holds a Reference Object, not a 2020-12 schema.
            return $resolved;
        }
        if (!$inSchema) {
            return [...$resolved, ...array_intersect_key($siblings, ['summary' => null, 'description' => null])];
        }
        $annotations = array_intersect_key($siblings, array_flip(self::SCHEMA_ANNOTATIONS));
        $assertions = array_diff_key($siblings, $annotations);
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
