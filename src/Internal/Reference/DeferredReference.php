<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Reference;

/**
 * A `$ref` that reached back to a schema still being resolved, standing in
 * for the local `$ref` it becomes once the Schema Object root has its
 * `$defs`. An object rather than an array so that nothing walking the
 * half-resolved tree mistakes it for a reference to resolve again.
 *
 * @internal
 */
final readonly class DeferredReference
{
    /**
     * @param string $reference the `$ref` as the document wrote it
     * @param string $name the `$defs` member it will point to
     * @param array<array-key, mixed> $shape the decoding keywords of the target, as written
     */
    public function __construct(
        public string $reference,
        public string $name,
        public array $shape,
    ) {}

    /** @return array<array-key, mixed> */
    public function toSchema(): array
    {
        return ['$ref' => '#/$defs/' . str_replace(['~', '/'], ['~0', '~1'], $this->name), ...$this->shape];
    }
}
