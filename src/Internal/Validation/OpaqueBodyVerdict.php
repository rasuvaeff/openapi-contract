<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Validation;

use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaDialect;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaValidator;

/**
 * How far a declared media type the package cannot decode — anything that is
 * neither JSON nor a form — can be judged against its schema. The request and
 * response validators word the outcome differently, but the decision itself is
 * one fail-closed rule and lives here so the two directions cannot drift.
 *
 * @internal
 */
enum OpaqueBodyVerdict
{
    /** No schema constrains the payload, so nothing can be violated. */
    case Opaque;

    /** A schema constrains the payload, but not one an undecoded body can be judged against. */
    case Unsupported;

    /** The raw payload is the string value the schema describes, and satisfies it. */
    case Valid;

    /** The raw payload is the string value the schema describes, and does not satisfy it. */
    case Invalid;

    /**
     * @param array<string, mixed>|null $schema the schema the media type
     *        declares, as {@see MessageReading::declaredSchema()} reads it
     */
    public static function of(
        ?array $schema,
        string $body,
        SchemaValidator $schemas,
        SchemaDialect $dialect,
        string $direction,
    ): self {
        if ($schema === null || $schema === []) {
            return self::Opaque;
        }
        if (!self::isStringSchema($schema)) {
            return self::Unsupported;
        }

        return $schemas->isValid($body, $schema, $dialect, direction: $direction) ? self::Valid : self::Invalid;
    }

    /**
     * Whether a raw non-JSON payload can be validated as the string value the
     * schema describes: `type: string`, or a type list of `string` (and
     * `null`) only.
     *
     * @param array<string, mixed> $schema
     */
    private static function isStringSchema(array $schema): bool
    {
        /** @var mixed $type */
        $type = $schema['type'] ?? null;
        if ($type === 'string') {
            return true;
        }
        if (!is_array($type) || !array_is_list($type) || !in_array('string', $type, strict: true)) {
            return false;
        }
        foreach ($type as $candidate) {
            if ($candidate !== 'string' && $candidate !== 'null') {
                return false;
            }
        }

        return true;
    }
}
