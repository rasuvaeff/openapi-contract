<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaValidator;

/**
 * Answers, for one Schema Object, the only question validation asks of it:
 * does this value satisfy it?
 *
 * This is the check the contract itself runs, not a second opinion. A schema
 * is read as its dialect spells it — OAS 3.0 writes nullability as `nullable`
 * and the exclusive bounds as booleans — and as its direction applies it: a
 * `readOnly` property is not required on a request and a `writeOnly` one is
 * not required on a response, recursively, while both stay declared and
 * typed. A consumer that reimplements any of that agrees with the contract
 * until it silently does not; {@see effective()} hands it the rewritten
 * schema instead.
 *
 * Compilation is cached per instance and keyed on the schema, the dialect and
 * the direction, so checking many values against the same schema compiles it
 * once. The cache never evicts: hold one instance per document, whose schemas
 * are finite, and not one fed an unbounded stream of distinct schemas. A
 * {@see Contract} already holds one and offers {@see Contract::accepts()};
 * this class is for the consumer that holds an {@see Operation} and no
 * contract.
 *
 * A schema is read as the contract reads one out of a document, with two
 * differences a hand-written schema can meet: a `$ref` is resolved only
 * inside the schema itself (`#/$defs/…`), every other target is refused; and
 * a schema that cannot be encoded as JSON — `NAN`, malformed UTF-8, more than
 * 512 levels — is refused, where a document schema is refused at load time.
 *
 * @api
 */
final readonly class SchemaCheck
{
    private SchemaValidator $schemas;

    public function __construct()
    {
        $this->schemas = new SchemaValidator();
    }

    /**
     * Whether the value satisfies the Schema Object.
     *
     * The value is judged as the backend reads JSON: an object is a
     * `stdClass`, the way `json_decode()` produces one without
     * `associative: true`, and an associative PHP array is a JSON *array*
     * that no `type: object` schema admits. A scalar needs no such care.
     *
     * @param array<string, mixed> $schema a Schema Object, as {@see Operation}
     *        carries it
     * @throws InvalidContract when the schema declares something this package
     *         cannot evaluate
     */
    public function accepts(
        mixed $value,
        array $schema,
        SchemaDialect $dialect,
        SchemaDirection $direction = SchemaDirection::Request,
    ): bool {
        return $this->schemas->isValid($value, $schema, $dialect, $direction);
    }

    /**
     * The Schema Object as one direction applies it: exactly the rewrite
     * {@see accepts()}, {@see Contract::accepts()} and the validators perform
     * before a value is judged, so a consumer that builds values for a
     * request or a response builds them against the schema they will be
     * checked by.
     *
     * A property the other direction owns (`readOnly` on a request,
     * `writeOnly` on a response) loses its `required` entry and keeps its
     * subschema; the rewrite recurses through `properties`, `items`,
     * `additionalProperties`, `allOf`, `anyOf` and `oneOf`, leaves `not`
     * alone, and is idempotent. Members it does not read are passed through
     * as written. The dialect plays no part: the rewrite is the same under
     * OAS 3.0 and 3.1.
     *
     * @param array<string, mixed> $schema a Schema Object, as {@see Operation}
     *        carries it
     * @return array<string, mixed>
     */
    public function effective(array $schema, SchemaDirection $direction): array
    {
        return $this->schemas->effectiveSchema($schema, $direction);
    }
}
