<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Schema;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Schema;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator as OpisValidator;
use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedSchema;

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
     * that carries them, and so are rewritten with it.
     */
    private const array DIRECTIONAL_KEYWORDS = ['properties', 'items', 'allOf', 'anyOf', 'oneOf'];

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
     * @param array<string, mixed> $schema
     */
    public function isValid(mixed $value, array $schema, SchemaDialect $dialect, string $direction = 'request'): bool
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
    private function compiledSchema(array $schema, SchemaDialect $dialect, string $direction): Schema
    {
        $key = hash('xxh128', $dialect->name . "\0" . $direction . "\0" . json_encode($schema, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        if (isset($this->compiled[$key])) {
            return $this->compiled[$key];
        }
        $object = $this->compiler->compile($this->effectiveSchema($schema, $direction), $dialect);

        try {
            return $this->compiled[$key] = $this->validator->loader()->loadObjectSchema($object);
        } catch (\Throwable $exception) {
            throw UnsupportedSchema::fromBackend($exception);
        }
    }

    /**
     * The schema as it constrains one direction: properties the other
     * direction owns (`readOnly` on a request, `writeOnly` on a response) are
     * dropped, along with their `required` entries, recursively through
     * `items` and the composition keywords.
     *
     * Dropping the last property drops `properties` itself rather than
     * leaving an empty map, which would forbid every property. What the
     * document says about undeclared properties keeps saying it: a schema
     * with `additionalProperties: false` then admits nothing, and one without
     * it still admits anything, exactly as it does for the other direction.
     * OAS implies no closed object, so this does not close one.
     *
     * Direction is the *only* reason a property is dropped. A member this
     * method does not recurse into — a boolean schema, or any shape it does
     * not read — is passed through untouched for the compiler and the backend
     * to judge. Dropping it here instead removed the property, its subschema
     * and its `required` entry from the check, which is the one outcome a
     * validator must never produce silently.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function effectiveSchema(array $schema, string $direction): array
    {
        if ($direction !== 'request' && $direction !== 'response') {
            throw new \InvalidArgumentException(sprintf('Unknown schema direction "%s"', $direction));
        }
        foreach (self::DIRECTIONAL_KEYWORDS as $keyword) {
            if (!array_key_exists($keyword, $schema)) {
                continue;
            }
            if ($keyword === 'properties' && is_array($schema[$keyword])) {
                $flag = $direction === 'request' ? 'readOnly' : 'writeOnly';
                $properties = [];
                /** @var array<string, true> $dropped */
                $dropped = [];
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
                        $dropped[(string) $name] = true;

                        continue;
                    }
                    $properties[$name] = $this->effectiveSchema($property, $direction);
                }
                if ($properties === []) {
                    unset($schema['properties']);
                } else {
                    $schema['properties'] = $properties;
                }
                /** @var mixed $required */
                $required = $schema['required'] ?? null;
                if (is_array($required)) {
                    $schema['required'] = array_values(array_filter($required, static fn(mixed $name): bool => !is_string($name) || !isset($dropped[$name])));
                }
            } elseif ($keyword === 'items' && is_array($schema[$keyword]) && !array_is_list($schema[$keyword])) {
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
