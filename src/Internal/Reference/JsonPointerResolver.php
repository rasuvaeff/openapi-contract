<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Reference;

use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedReference;

/**
 * Resolves only same-document fragment references under explicit work limits.
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
        if (++$this->resolvedNodes > $this->maximumResolvedNodes) {
            throw new \InvalidArgumentException(sprintf(
                'OpenAPI document exceeds the reference-resolution budget of %d nodes',
                $this->maximumResolvedNodes,
            ));
        }

        while (array_key_exists('$ref', $node)) {
            $reference = $this->localReference($node['$ref']);
            if (++$referenceDepth > $this->maximumReferenceDepth) {
                throw new \InvalidArgumentException('OpenAPI $ref chain is too deep (possible circular reference)');
            }

            $resolved = $this->lookup($reference);
            unset($node['$ref']);
            $node = [...$resolved, ...$node];
        }

        /** @var mixed $value */
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->resolve($value, $referenceDepth);
            }
        }

        return $node;
    }

    private function localReference(mixed $reference): string
    {
        if (!is_string($reference)) {
            throw UnsupportedReference::forValue($reference);
        }
        if (!str_starts_with($reference, '#')) {
            throw UnsupportedReference::forValue($reference);
        }

        return $reference;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function lookup(string $reference): array
    {
        if ($reference === '#') {
            return $this->document;
        }
        if (!str_starts_with($reference, '#/')) {
            throw UnsupportedReference::forValue($reference);
        }

        $node = $this->document;
        foreach (explode('/', substr($reference, 2)) as $segment) {
            $segment = $this->decodeSegment($segment, $reference);
            if (!array_key_exists($segment, $node)) {
                throw new \InvalidArgumentException(sprintf('Unresolvable $ref "%s" in OpenAPI document', $reference));
            }
            if (!is_array($node[$segment])) {
                throw new \InvalidArgumentException(sprintf('$ref "%s" must point to an object', $reference));
            }

            $node = $node[$segment];
        }

        return $node;
    }

    private function decodeSegment(string $segment, string $reference): string
    {
        if (preg_match('/~(?:[^01]|$)/', $segment) === 1) {
            throw new \InvalidArgumentException(sprintf('Invalid JSON Pointer escape in $ref "%s"', $reference));
        }

        return str_replace(['~1', '~0'], ['/', '~'], $segment);
    }
}
