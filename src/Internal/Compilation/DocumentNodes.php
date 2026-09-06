<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Compilation;

/**
 * Counts what a parsed document actually expands into.
 *
 * The byte budget measures a file; YAML anchors make nodes out of no bytes at
 * all, and the reference resolver's own budget counts only the nodes it
 * descends into — deliberately not the data-bearing keywords (`enum`,
 * `example`, `default`, `const`) where an alias is just as welcome. So a
 * document under every declared budget could still expand into hundreds of
 * millions of nodes and take the process down with it.
 *
 * Counting is iterative rather than recursive: the structure being measured is
 * precisely the one that must not be allowed to exhaust anything, stack
 * included.
 *
 * @internal
 */
final class DocumentNodes
{
    /**
     * @param array<array-key, mixed> $document
     *
     * @return int|null the node count, or `null` when the document exceeds
     *         the budget — counting stops there, so an oversized document
     *         costs the budget and not its own size
     */
    public static function within(array $document, int $budget): ?int
    {
        $count = 0;
        $stack = [$document];
        while ($stack !== []) {
            /** @var array<array-key, mixed> $node */
            $node = array_pop($stack);
            /** @var mixed $value */
            foreach ($node as $value) {
                if (++$count > $budget) {
                    return null;
                }
                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }

        return $count;
    }
}
