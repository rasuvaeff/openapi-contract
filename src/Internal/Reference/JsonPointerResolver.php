<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Reference;

use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedReference;
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
    private int $resolvedNodes = 0;

    /**
     * @param array<string, mixed> $document
     */
    public function __construct(
        private readonly array $document,
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
     *
     * @return array<array-key, mixed>
     */
    public function resolve(array $node, int $referenceDepth = 0): array
    {
        return $this->resolveIn($node, $this->graph?->entryPath() ?? '', $referenceDepth);
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private function resolveIn(array $node, string $file, int $referenceDepth): array
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
                $resolved = $this->resolveIn($resolved, $targetFile, $referenceDepth);
            }
            unset($node['$ref']);
            $node = [...$resolved, ...$node];
        }

        /** @var mixed $value */
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->resolveIn($value, $file, $referenceDepth);
            }
        }

        return $node;
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
