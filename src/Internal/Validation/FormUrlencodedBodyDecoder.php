<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Validation;

use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterCodec;
use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterKind;
use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterStyle;

/**
 * @internal
 */
final readonly class FormUrlencodedBodyDecoder
{
    public function __construct(
        private ParameterCodec $parameters = new ParameterCodec(),
        private SchemaValueDecoder $values = new SchemaValueDecoder(),
    ) {}

    /**
     * @param array<string, mixed> $schema
     * @param array<array-key, mixed> $encoding
     */
    public function decode(string $body, array $schema, array $encoding = []): object
    {
        if ($this->values->kind($schema) !== ParameterKind::Object) {
            throw new BodyDecodingFailed('Form-urlencoded request bodies require an object schema');
        }

        $pairs = $this->pairs($body);
        $properties = $this->values->properties($schema);
        $consumed = [];
        $result = [];
        foreach ($properties as $name => $property) {
            $configuration = $this->encoding($encoding[$name] ?? null, $name);
            $explode = $configuration['explode'];
            $kind = $this->values->kind($property);
            $names = [$name];
            if ($kind === ParameterKind::Object && $explode) {
                $names = array_keys($this->values->properties($property));
            }
            $selected = [];
            foreach ($pairs as $index => $pair) {
                if (in_array($pair['name'], $names, strict: true)) {
                    $selected[] = $pair['wire'];
                    $consumed[$index] = true;
                }
            }
            if ($selected === []) {
                continue;
            }

            try {
                $parsed = $this->parameters->parse(
                    name: $name,
                    wire: implode('&', $selected),
                    style: ParameterStyle::Form,
                    explode: $explode,
                    kind: $kind,
                );
                $result[$name] = $this->values->coerce($parsed, $property);
            } catch (\InvalidArgumentException $exception) {
                throw new BodyDecodingFailed(sprintf('Form property "%s" cannot be deserialized', $name), $exception->getCode(), previous: $exception);
            }
        }

        /** @var mixed $additionalValue */
        $additionalValue = $schema['additionalProperties'] ?? [];
        $additional = is_array($additionalValue) && !array_is_list($additionalValue)
            ? ($this->values->schema($additionalValue) ?? [])
            : [];
        foreach ($pairs as $index => $pair) {
            if (isset($consumed[$index])) {
                continue;
            }
            $value = $this->values->scalar($pair['value'], $additional);
            if (!array_key_exists($pair['name'], $result)) {
                $result[$pair['name']] = $value;
                continue;
            }
            $existing = $result[$pair['name']];
            $result[$pair['name']] = is_array($existing) ? [...$existing, $value] : [$existing, $value];
        }

        return (object) $result;
    }

    /**
     * @return list<array{name: string, value: string, wire: string}>
     */
    private function pairs(string $body): array
    {
        $result = [];
        foreach (explode('&', $body) as $pair) {
            if ($pair === '') {
                continue;
            }
            if (preg_match('/%(?![0-9A-Fa-f]{2})/', $pair) === 1) {
                throw new BodyDecodingFailed('Form-urlencoded request body contains invalid percent encoding');
            }
            [$rawName, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $wire = str_replace('+', '%20', $rawName . '=' . $rawValue);
            $result[] = [
                'name' => rawurldecode(str_replace('+', '%20', $rawName)),
                'value' => rawurldecode(str_replace('+', '%20', $rawValue)),
                'wire' => $wire,
            ];
        }

        return $result;
    }

    /** @return array{explode: bool} */
    private function encoding(mixed $value, string $name): array
    {
        if ($value === null) {
            return ['explode' => true];
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new BodyDecodingFailed(sprintf('Encoding for form property "%s" must be an object', $name));
        }
        /** @var mixed $style */
        $style = $value['style'] ?? 'form';
        if ($style !== 'form') {
            throw new BodyDecodingFailed(sprintf('Encoding style "%s" is not supported for form property "%s"', is_string($style) ? $style : get_debug_type($style), $name));
        }
        /** @var mixed $explode */
        $explode = $value['explode'] ?? true;
        if (!is_bool($explode)) {
            throw new BodyDecodingFailed(sprintf('Encoding explode for form property "%s" must be a boolean', $name));
        }

        return ['explode' => $explode];
    }
}
