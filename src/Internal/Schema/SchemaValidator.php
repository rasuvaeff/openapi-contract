<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Schema;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Schema;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Schemas\ExceptionSchema;
use Opis\JsonSchema\Validator as OpisValidator;
use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedSchema;
use Rasuvaeff\OpenApiContract\Internal\Schema\Backend\Parser;
use Rasuvaeff\OpenApiContract\SchemaDialect;
use Rasuvaeff\OpenApiContract\SchemaDirection;

/**
 * Validates a value against an OAS Schema Object, hiding the backend.
 *
 * Compilation is cached per contract instance. It is not cheap — the
 * directional rewrite, a JSON round trip and the backend's own parse — and a
 * document offers the same handful of schemas over and over, once per
 * parameter per request. Doing it on every call made `Contract::fromArray()`
 * a parser and `validateRequest()` the compiler, which is the wrong way round
 * for a type named after a compiled contract.
 *
 * @internal
 */
final class SchemaValidator
{
    /** @var array<string, Schema> */
    private array $compiled = [];

    /**
     * Keywords whose subschemas constrain the same direction as the schema
     * that carries them, and so are rewritten with it. `additionalProperties`
     * is the map-of-objects form and used to be missing: a document keyed
     * its items by name instead of listing them, and the `readOnly` property
     * every item dropped elsewhere stayed required there. `not` is left
     * alone on purpose — what a `readOnly` property means under a negation
     * is not something either specification says.
     */
    private const array DIRECTIONAL_KEYWORDS = ['$defs', 'properties', 'items', 'additionalProperties', 'allOf', 'anyOf', 'oneOf'];

    /**
     * Every keyword under which the compiler emits a subschema, by the shape
     * of its value, so {@see assertParsed()} can visit each node the backend
     * would otherwise parse on the first value that reaches it.
     *
     * @var array{single: list<string>, list: list<string>, map: list<string>}
     */
    private const array SUBSCHEMA_KEYWORDS = [
        'single' => ['additionalProperties', 'items', 'not'],
        'list' => ['allOf', 'anyOf', 'oneOf'],
        'map' => ['$defs', 'properties'],
    ];

    /**
     * How many errors the backend collects under one keyword before it stops
     * — per keyword, so a tree of errors has more leaves than this.
     */
    private const int MAX_ERRORS = 20;

    /**
     * How many leaf failures {@see failures()} reports for one value. The
     * same count as the backend's, so the first page of a body that is wrong
     * everywhere is the same size whichever way the errors nest.
     */
    private const int MAX_FAILURES = self::MAX_ERRORS;

    private readonly OpisValidator $validator;

    public function __construct(
        private readonly SchemaCompiler $compiler = new SchemaCompiler(),
    ) {
        // The one draft every schema is compiled to, with `multipleOf` judged
        // on the decimals rather than on the doubles (#151).
        $parser = new Parser(options: [
            'allowDataKeyword' => false,
            'allowDefaults' => false,
            'allowFilters' => false,
            'allowGlobals' => false,
            'allowKeywordValidators' => false,
            'allowMappers' => false,
            'allowPragmas' => false,
            'allowSlots' => false,
            'allowTemplates' => false,
        ]);
        $formats = $parser->getFormatResolver() ?? throw new \LogicException('The schema backend offers no format resolver');
        // OpenAPI defines `int32` and `int64` as ranges of the integer type
        // (RFC 8259 leaves a number's range to the interchange), and the
        // backend knows neither: without these two an integer `format` was
        // an annotation and a value outside the declared width passed.
        $formats->registerCallable('integer', 'int32', static fn(int|float $value): bool => $value >= -2_147_483_648 && $value <= 2_147_483_647);
        $formats->registerCallable('integer', 'int64', static fn(int|float $value): bool => is_int($value) || ($value >= -9_223_372_036_854_775_808 && $value < 9_223_372_036_854_775_808));
        $loader = new SchemaLoader(
            parser: $parser,
            decodeJsonString: true,
        );
        $this->validator = new OpisValidator(
            loader: $loader,
            max_errors: self::MAX_ERRORS,
            stop_at_first_error: false,
        );
    }

    /**
     * Compiles the schema for one direction — and caches it — without judging
     * a value, so a contract can pay for every schema of its document while
     * it is built and a schema this package cannot evaluate is refused there,
     * not from the first message that happens to reach it.
     *
     * @param array<string, mixed> $schema
     * @throws UnsupportedSchema
     */
    public function compile(array $schema, SchemaDialect $dialect, SchemaDirection $direction): void
    {
        $this->compiledSchema($schema, $dialect, $direction);
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function isValid(mixed $value, array $schema, SchemaDialect $dialect, SchemaDirection $direction = SchemaDirection::Request): bool
    {
        return $this->validationError($value, $schema, $dialect, $direction) === null;
    }

    /**
     * The leaf failures of a value against a schema, in the backend's order,
     * without the backend: where in the value each assertion failed, and
     * which. Empty when the value is valid.
     *
     * The list is bounded by {@see MAX_FAILURES}: the backend stops
     * collecting at {@see MAX_ERRORS} errors per keyword, but a tree of
     * errors has more leaves than that, and one violation per leaf is a
     * diagnostic, not an inventory.
     *
     * @param array<string, mixed> $schema
     * @return list<SchemaFailure>
     */
    public function failures(
        mixed $value,
        array $schema,
        SchemaDialect $dialect,
        SchemaDirection $direction = SchemaDirection::Request,
    ): array {
        $error = $this->validationError($value, $schema, $dialect, $direction);
        if (!$error instanceof ValidationError) {
            return [];
        }

        $failures = [];
        foreach ($this->leaves($error) as $leaf) {
            // Two branches of a union that both demand the member the value
            // lacks report the same leaf twice; once says it.
            $failures[implode("\0", [...$leaf->path, $leaf->keyword])] ??= $leaf;
        }

        return array_slice(array_values($failures), 0, self::MAX_FAILURES);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function validationError(
        mixed $value,
        array $schema,
        SchemaDialect $dialect,
        SchemaDirection $direction,
    ): ?ValidationError {
        $compiled = $this->compiledSchema($schema, $dialect, $direction);

        try {
            $error = $this->validator->schemaValidation($value, $compiled);
        } catch (\Throwable $exception) {
            // Backends are implementation details: a document the compiler
            // accepted but the backend chokes on leaves as a package type, on
            // the exit `compile()` above already uses.
            throw UnsupportedSchema::fromBackend($exception);
        }

        return $error instanceof ValidationError ? $error : null;
    }

    /**
     * The leaves below one backend error: the error itself when it has no
     * sub-errors, else the leaves of each sub-error — or, for a discriminated
     * union, of the one branch the discriminator names.
     *
     * @return list<SchemaFailure>
     */
    private function leaves(ValidationError $error): array
    {
        $children = $this->subErrors($error);
        $discriminated = $this->discriminate($error, $children);
        if ($discriminated instanceof SchemaFailure) {
            return [$discriminated];
        }
        if ($discriminated !== null) {
            $children = $discriminated;
        }
        if ($children === []) {
            return $this->leaf($error);
        }

        $leaves = [];
        foreach ($children as $child) {
            $leaves = [...$leaves, ...$this->leaves($child)];
        }

        return $leaves;
    }

    /**
     * A backend error with nothing below it. `required` is reported on the
     * object that lacks the member, and the member is what the reader is
     * after: one failure per missing name, at the path the member would
     * have had, with nothing as its value.
     *
     * @return list<SchemaFailure>
     */
    private function leaf(ValidationError $error): array
    {
        $path = $this->dataPath($error);
        $expected = $this->assertion($error);
        if ($error->keyword() === 'required') {
            /** @var mixed $missing */
            $missing = $error->args()['missing'] ?? null;
            if (is_array($missing) && $missing !== []) {
                $leaves = [];
                /** @var mixed $name */
                foreach ($missing as $name) {
                    if (is_string($name)) {
                        $leaves[] = new SchemaFailure(path: [...$path, $name], keyword: 'required', actual: null, expected: $expected);
                    }
                }

                return $leaves;
            }
        }

        return [new SchemaFailure(path: $path, keyword: $error->keyword(), actual: $error->data()->value(), expected: $expected)];
    }

    /**
     * The assertion an error is about: the keyword and its value in the
     * subschema that carries it, as the compiler spelled it.
     *
     * @return array<string, mixed>
     */
    private function assertion(ValidationError $error, ?string $keyword = null): array
    {
        $keyword ??= $error->keyword();
        $schema = $error->schema()->info()->data();
        if (!$schema instanceof \stdClass || !property_exists($schema, $keyword)) {
            return [];
        }

        /** @var mixed $value */
        $value = json_decode(json_encode($schema->{$keyword}, JSON_THROW_ON_ERROR), associative: true, flags: JSON_THROW_ON_ERROR);

        return [$keyword => $value];
    }

    /** @return list<ValidationError> */
    private function subErrors(ValidationError $error): array
    {
        $children = [];
        /** @var mixed $child */
        foreach ($error->subErrors() as $child) {
            if ($child instanceof ValidationError) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /** @return list<string|int> */
    private function dataPath(ValidationError $error): array
    {
        $path = [];
        /** @var mixed $part */
        foreach ($error->data()->fullPath() as $part) {
            if (is_string($part) || is_int($part)) {
                $path[] = $part;
            }
        }

        return $path;
    }

    /**
     * `discriminator` is an OpenAPI annotation the backend does not read: a
     * `oneOf`/`anyOf` beside one is evaluated branch by branch, as the README
     * pins, and reported branch by branch — the errors of every branch the
     * value was never meant for, beside the one it was. When the value names
     * a branch, only that branch's errors are followed; when it names none,
     * that is the failure, and the one the reader is after.
     *
     * A branch is named through `mapping` — a `$ref` or a component name —
     * or, without one, by the component name the value spells. The compiler
     * keeps a referenced branch as a local `$ref` into the schema's `$defs`,
     * named after the component's JSON Pointer, and that name is what the
     * value is matched against; a branch written inline has no name and is
     * never chosen.
     *
     * @param list<ValidationError> $children
     *
     * @return null|SchemaFailure|list<ValidationError> the branch's errors,
     *         the failure that no branch is named, or null when the error is
     *         not a discriminated union or the value carries no discriminator
     */
    private function discriminate(ValidationError $error, array $children): SchemaFailure|array|null
    {
        $keyword = $error->keyword();
        if (($keyword !== 'oneOf' && $keyword !== 'anyOf') || $children === []) {
            return null;
        }
        $schema = $error->schema()->info()->data();
        if (!$schema instanceof \stdClass) {
            return null;
        }
        /** @var mixed $discriminator */
        $discriminator = $schema->discriminator ?? null;
        if (!$discriminator instanceof \stdClass) {
            return null;
        }
        /** @var mixed $propertyName */
        $propertyName = $discriminator->propertyName ?? null;
        /** @var mixed $value */
        $value = $error->data()->value();
        if (!is_string($propertyName) || !$value instanceof \stdClass) {
            return null;
        }
        /** @var mixed $discriminatorValue */
        $discriminatorValue = $value->{$propertyName} ?? null;
        if (!is_string($discriminatorValue)) {
            return null;
        }
        /** @var mixed $mapping */
        $mapping = $discriminator->mapping ?? null;
        /** @var mixed $mapped */
        $mapped = $mapping instanceof \stdClass ? $mapping->{$discriminatorValue} ?? null : null;
        $name = $this->componentName(is_string($mapped) ? $mapped : $discriminatorValue);
        /** @var mixed $branches */
        $branches = $schema->{$keyword} ?? null;
        $index = is_array($branches) ? $this->branchIndex($branches, $name) : null;
        if ($index === null) {
            return new SchemaFailure(
                path: [...$this->dataPath($error), $propertyName],
                keyword: 'discriminator',
                actual: $discriminatorValue,
                expected: $this->assertion($error, 'discriminator'),
            );
        }
        foreach ($children as $child) {
            $path = $child->schema()->info()->path();
            if (($path[count($path) - 1] ?? null) === $index && ($path[count($path) - 2] ?? null) === $keyword) {
                return [$child];
            }
        }

        // The named branch accepted the value: a `oneOf` that matched more
        // than one branch. The reader was told which branch was meant; the
        // rest of the report is the backend's.
        return null;
    }

    /**
     * The `$defs` name a discriminator target denotes: a component name is
     * `#/components/schemas/<name>`, a reference is its JSON Pointer with the
     * separators spelled as `.`, as the compiler names a def. A reference
     * into another file keeps only the fragment: the file's display path is
     * the compiler's to know, and the fragment is compared as a suffix. The
     * specification lets a mapping value be either, and tells them apart by
     * nothing; a value with a fragment, a `/` or a document extension is a
     * reference, anything else is a name.
     */
    private function componentName(string $target): string
    {
        $hash = strpos($target, '#');
        if ($hash === false && !str_contains($target, '/') && preg_match('/\.(?:json|ya?ml)\z/i', $target) !== 1) {
            return 'components.schemas.' . $target;
        }
        $fragment = $hash === false ? '' : substr($target, $hash + 1);

        return ($hash === 0 ? '' : ':') . ($fragment === '' ? 'document' : str_replace('/', '.', ltrim($fragment, '/')));
    }

    /**
     * The local `$defs` reference a compiled branch is, or null for a branch
     * written inline. A 3.1 branch whose `$ref` carried sibling assertions
     * is compiled to `allOf` with the reference first, and is read there.
     */
    private function branchReference(\stdClass $branch): ?string
    {
        /** @var mixed $reference */
        $reference = $branch->{'$ref'} ?? null;
        if ($reference === null) {
            /** @var mixed $conjunction */
            $conjunction = $branch->allOf ?? null;
            /** @var mixed $first */
            $first = is_array($conjunction) ? $conjunction[0] ?? null : null;
            /** @var mixed $reference */
            $reference = $first instanceof \stdClass ? $first->{'$ref'} ?? null : null;
        }

        return is_string($reference) && str_starts_with($reference, '#/$defs/') ? $reference : null;
    }

    /**
     * @param array<array-key, mixed> $branches
     */
    private function branchIndex(array $branches, string $name): ?int
    {
        /** @var mixed $branch */
        foreach ($branches as $index => $branch) {
            if (!$branch instanceof \stdClass || !is_int($index)) {
                continue;
            }
            $reference = $this->branchReference($branch);
            if ($reference === null) {
                continue;
            }
            $def = str_replace(['~1', '~0'], ['/', '~'], substr($reference, strlen('#/$defs/')));
            if ($def === $name || (str_starts_with($name, ':') && str_ends_with($def, $name))) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The cache key is the schema itself, so two Media Type Objects that
     * declare the same shape share one compilation and a schema that differs
     * by a single keyword does not. Direction and dialect are part of it
     * because both change what the compiled form asserts.
     *
     * @param array<string, mixed> $schema
     */
    private function compiledSchema(array $schema, SchemaDialect $dialect, SchemaDirection $direction): Schema
    {
        $this->assertObjectShape($schema);

        try {
            $encoded = json_encode($schema, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $exception) {
            // NAN/INF, malformed UTF-8 or a nesting past 512 levels: a document
            // schema is checked for all three at compile time, a hand-passed
            // one is not, and the refusal should still be a contract error.
            throw UnsupportedSchema::fromBackend($exception);
        }
        $key = hash('xxh128', $dialect->name . "\0" . $direction->name . "\0" . $encoded);
        if (isset($this->compiled[$key])) {
            return $this->compiled[$key];
        }
        $object = $this->compiler->compile($this->effectiveSchema($schema, $direction), $dialect);

        try {
            $compiled = $this->validator->loader()->loadObjectSchema($object);
            $this->assertParsed($compiled, $object);
        } catch (\Throwable $exception) {
            throw UnsupportedSchema::fromBackend($exception);
        }

        return $this->compiled[$key] = $compiled;
    }

    /**
     * The backend parses a schema node the first time a value reaches it, and
     * a node it cannot parse — a `pattern` that is not a regex, a `minimum`
     * that is not a number — becomes a schema that throws when validated,
     * silently, until then. Every node is parsed here instead, the root and
     * each subschema the compiler emitted, so the refusal comes out of the
     * compilation that a contract runs at load time.
     */
    private function assertParsed(Schema $compiled, \stdClass $node): void
    {
        if ($compiled instanceof ExceptionSchema) {
            // The only way the backend gives up the exception it wrapped.
            $this->validator->schemaValidation(null, $compiled);
        }
        $loader = $this->validator->loader();
        foreach (self::SUBSCHEMA_KEYWORDS['single'] as $keyword) {
            /** @var mixed $member */
            $member = $node->{$keyword} ?? null;
            if ($member instanceof \stdClass) {
                $this->assertParsed($loader->loadObjectSchema($member), $member);
            }
        }
        foreach (self::SUBSCHEMA_KEYWORDS['list'] as $keyword) {
            /** @var mixed $members */
            $members = $node->{$keyword} ?? null;
            if (!is_array($members)) {
                continue;
            }
            /** @var mixed $member */
            foreach ($members as $member) {
                if ($member instanceof \stdClass) {
                    $this->assertParsed($loader->loadObjectSchema($member), $member);
                }
            }
        }
        foreach (self::SUBSCHEMA_KEYWORDS['map'] as $keyword) {
            /** @var mixed $members */
            $members = $node->{$keyword} ?? null;
            if (!$members instanceof \stdClass) {
                continue;
            }
            /** @var mixed $member */
            foreach (get_object_vars($members) as $member) {
                if ($member instanceof \stdClass) {
                    $this->assertParsed($loader->loadObjectSchema($member), $member);
                }
            }
        }
    }

    /**
     * A document schema never arrives as a list — the compiler refuses the
     * shape at load time — but a schema handed to `accepts()` can, and read
     * as the empty schema it accepted every value.
     *
     * @param array<array-key, mixed> $schema
     */
    private function assertObjectShape(array $schema): void
    {
        foreach (array_keys($schema) as $key) {
            if (!is_string($key)) {
                throw UnsupportedSchema::atKeyword('schema', 'expected a schema object');
            }
        }
    }

    /**
     * The schema as it constrains one direction. A property the other
     * direction owns (`readOnly` on a request, `writeOnly` on a response)
     * loses its `required` entry and nothing else: it stays declared and
     * typed, so a value that carries it is still judged by its subschema and
     * a closed object (`additionalProperties: false`) still admits it. That
     * is what both specifications say — the property "SHOULD NOT be sent" in
     * the foreign direction, and "the required will take effect on the
     * response only". Dropping the subschema, as this did, made the verdict
     * depend on `additionalProperties`: an open object stopped checking the
     * type and a closed one rejected the property the document declared.
     *
     * The rewrite recurses through `properties`, `items`,
     * `additionalProperties`, the composition keywords and `$defs` — including
     * into the foreign property itself, whose own members may be flagged.
     *
     * Direction is the *only* reason a `required` entry is dropped. A member
     * this method does not recurse into — a boolean schema, or any shape it
     * does not read — is passed through untouched for the compiler and the
     * backend to judge, because dropping it here removes a check the document
     * made, which is the one outcome a validator must never produce silently.
     *
     * The rewrite is idempotent, and it is what {@see \Rasuvaeff\OpenApiContract\SchemaCheck::effective()}
     * exports: a consumer that generates values for one direction reads the
     * same effective schema the validator judges them by.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function effectiveSchema(array $schema, SchemaDirection $direction): array
    {
        foreach (self::DIRECTIONAL_KEYWORDS as $keyword) {
            if (!array_key_exists($keyword, $schema)) {
                continue;
            }
            if ($keyword === '$defs' && is_array($schema[$keyword])) {
                // A def is a schema a local `$ref` reaches — the member of a
                // reference cycle the compiler could not inline — and is
                // read in the same direction as the schema that holds it.
                // Not a property: nothing here drops a `required` entry.
                $defs = [];
                /** @var array<array-key, mixed> $defMap */
                $defMap = $schema[$keyword];
                foreach (array_keys($defMap) as $name) {
                    /** @var mixed $def */
                    $def = $defMap[$name];
                    if (!is_array($def) || array_is_list($def)) {
                        $defs[$name] = $def;

                        continue;
                    }
                    /** @var array<string, mixed> $def */
                    $defs[$name] = $this->effectiveSchema($def, $direction);
                }
                $schema['$defs'] = $defs;
            } elseif ($keyword === 'properties' && is_array($schema[$keyword])) {
                $flag = $direction->foreignFlag();
                $properties = [];
                /** @var array<string, true> $foreign */
                $foreign = [];
                /** @var array<array-key, mixed> $propertyMap */
                $propertyMap = $schema[$keyword];
                foreach (array_keys($propertyMap) as $name) {
                    /** @var mixed $property */
                    $property = $propertyMap[$name];
                    if (!is_array($property) || array_is_list($property)) {
                        $properties[$name] = $property;

                        continue;
                    }
                    /** @var array<string, mixed> $property */
                    if (($property[$flag] ?? false) === true) {
                        $foreign[(string) $name] = true;
                    }
                    $properties[$name] = $this->effectiveSchema($property, $direction);
                }
                $schema['properties'] = $properties;
                /** @var mixed $required */
                $required = $schema['required'] ?? null;
                if (is_array($required)) {
                    $schema['required'] = array_values(array_filter($required, static fn(mixed $name): bool => !is_string($name) || !isset($foreign[$name])));
                }
            } elseif (($keyword === 'items' || $keyword === 'additionalProperties') && is_array($schema[$keyword]) && !array_is_list($schema[$keyword])) {
                /** @var array<string, mixed> $items */
                $items = $schema[$keyword];
                $schema[$keyword] = $this->effectiveSchema($items, $direction);
            } elseif (is_array($schema[$keyword]) && array_is_list($schema[$keyword])) {
                /** @var list<mixed> $parts */
                $parts = $schema[$keyword];
                $schema[$keyword] = array_map(function (mixed $part) use ($direction): mixed {
                    if (!is_array($part) || array_is_list($part)) {
                        return $part;
                    }

                    /** @var array<string, mixed> $part */
                    return $this->effectiveSchema($part, $direction);
                }, $parts);
            }
        }

        return $schema;
    }
}
