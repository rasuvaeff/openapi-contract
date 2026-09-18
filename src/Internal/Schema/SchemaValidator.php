<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Schema;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Schema;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Schemas\ExceptionSchema;
use Opis\JsonSchema\Validator as OpisValidator;
use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedSchema;
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
    private const array DIRECTIONAL_KEYWORDS = ['properties', 'items', 'additionalProperties', 'allOf', 'anyOf', 'oneOf'];

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

    private readonly OpisValidator $validator;

    public function __construct(
        private readonly SchemaCompiler $compiler = new SchemaCompiler(),
    ) {
        $parser = new SchemaParser(options: [
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
            max_errors: 20,
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
        $compiled = $this->compiledSchema($schema, $dialect, $direction);

        try {
            return !$this->validator->schemaValidation($value, $compiled) instanceof ValidationError;
        } catch (\Throwable $exception) {
            // Backends are implementation details: a document the compiler
            // accepted but the backend chokes on leaves as a package type, on
            // the exit `compile()` above already uses.
            throw UnsupportedSchema::fromBackend($exception);
        }
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
     * `additionalProperties` and the composition keywords — including into
     * the foreign property itself, whose own members may be flagged.
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
            if ($keyword === 'properties' && is_array($schema[$keyword])) {
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
