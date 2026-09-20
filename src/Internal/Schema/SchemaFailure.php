<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Schema;

/**
 * One leaf failure of a value against a schema, as {@see SchemaValidator}
 * reports it without the backend: where in the value, which keyword, and
 * the member that failed it.
 *
 * @internal
 */
final readonly class SchemaFailure
{
    /**
     * @param list<string|int> $path the failing member's path in the value,
     *        root first; empty for the value itself
     * @param string $keyword the assertion keyword that failed — or
     *        `discriminator`, when the value names no branch of a
     *        discriminated union
     * @param mixed $actual the member's value; null for a `required` member
     *        the value lacks
     */
    public function __construct(
        public array $path,
        public string $keyword,
        public mixed $actual,
    ) {}
}
