<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Schema;

use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator as OpisValidator;

/**
 * @internal
 */
final readonly class SchemaValidator
{
    private OpisValidator $validator;

    public function __construct(
        private SchemaCompiler $compiler = new SchemaCompiler(),
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
        return $this->validator->validate($value, $this->compiler->compile($this->effectiveSchema($schema, $direction), $dialect))->isValid();
    }

    /** @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function effectiveSchema(array $schema, string $direction): array
    {
        if ($direction !== 'request' && $direction !== 'response') {
            throw new \InvalidArgumentException(sprintf('Unknown schema direction "%s"', $direction));
        }
        foreach (['properties' => true, 'items' => false, 'allOf' => false, 'anyOf' => false, 'oneOf' => false] as $keyword => $_) {
            if (!array_key_exists($keyword, $schema)) {
                continue;
            }
            if ($keyword === 'properties' && is_array($schema[$keyword])) {
                $properties = [];
                /** @var array<array-key, mixed> $propertyMap */
                $propertyMap = $schema[$keyword];
                foreach (array_keys($propertyMap) as $name) {
                    if (!is_string($name) || !is_array($propertyMap[$name]) || array_is_list($propertyMap[$name])) {
                        continue;
                    }
                    /** @var array<string, mixed> $property */
                    $property = $propertyMap[$name];
                    $flag = $direction === 'request' ? 'readOnly' : 'writeOnly';
                    if (($property[$flag] ?? false) === true) {
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
                    $schema['required'] = array_values(array_filter($required, static fn(mixed $name): bool => is_string($name) && array_key_exists($name, $properties)));
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
