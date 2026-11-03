<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Internal\Validation\BodyDecodingFailed;
use Rasuvaeff\OpenApiContract\Internal\Validation\FormUrlencodedBodyDecoder;
use Rasuvaeff\OpenApiContract\Internal\Validation\SchemaValueDecoder;
use Rasuvaeff\OpenApiContract\InvalidContract;
use Rasuvaeff\OpenApiContract\Tests\Support\Refusal;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(FormUrlencodedBodyDecoder::class)]
#[Covers(SchemaValueDecoder::class)]
final class FormUrlencodedBodyDecoderTest
{
    /**
     * @param array<string, mixed> $schema
     * @param array<array-key, mixed> $encoding
     * @param array{string, mixed} $expected `['ok', decoded body]` or `[exception class, message]`
     */
    #[DataProvider('decodingProvider')]
    public function decodesOrRefusesWithTheReasonItGives(string $body, array $schema, array $encoding, array $expected): void
    {
        $actual = Refusal::quietly(static function () use ($body, $schema, $encoding): array {
            try {
                $decoded = (new FormUrlencodedBodyDecoder())->decode($body, $schema, $encoding);
            } catch (\Throwable $exception) {
                return [$exception::class, $exception->getMessage()];
            }

            return ['ok', json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), associative: true, flags: JSON_THROW_ON_ERROR)];
        });

        Assert::same($actual, $expected);
    }

    /** @return iterable<string, array{string, array<string, mixed>, array<array-key, mixed>, array{string, mixed}}> */
    public static function decodingProvider(): iterable
    {
        $failed = BodyDecodingFailed::class;
        $open = ['type' => 'object'];
        $typed = ['type' => 'object', 'properties' => [
            'n' => ['type' => 'integer'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            'o' => ['type' => 'object', 'properties' => ['k' => ['type' => 'integer'], 'j' => ['type' => 'integer']]],
        ]];

        yield 'a schema that is not an object' => ['a=1', ['type' => 'string'], [], [$failed, 'Form-urlencoded request bodies require an object schema']];
        yield 'an empty body' => ['', $typed, [], ['ok', []]];
        yield 'empty pairs between real ones' => ['n=1&&n2=2&', $typed, [], ['ok', ['n' => 1, 'n2' => '2']]];
        yield 'a pair with a broken percent escape' => ['a=%zz', $open, [], [$failed, 'Form-urlencoded request body contains invalid percent encoding']];
        yield 'a pair with a truncated percent escape' => ['a=%4', $open, [], [$failed, 'Form-urlencoded request body contains invalid percent encoding']];
        yield 'plus is a space in a value' => ['a=x+y', $open, [], ['ok', ['a' => 'x y']]];
        yield 'plus is a space in a name' => ['a+b=1', $open, [], ['ok', ['a b' => '1']]];
        yield 'an encoded plus is a plus' => ['a=x%2By', $open, [], ['ok', ['a' => 'x+y']]];
        yield 'a pair without a value' => ['a', $open, [], ['ok', ['a' => '']]];
        yield 'a pair with an equals sign in the value' => ['a=b=c', $open, [], ['ok', ['a' => 'b=c']]];
        yield 'an undeclared name is a string' => ['x=1', $typed, [], ['ok', ['x' => '1']]];
        yield 'an undeclared name read by additionalProperties' => ['x=1', ['type' => 'object', 'additionalProperties' => ['type' => 'integer']], [], ['ok', ['x' => 1]]];
        yield 'an undeclared name under a boolean additionalProperties' => ['x=1', ['type' => 'object', 'additionalProperties' => false], [], ['ok', ['x' => '1']]];
        yield 'an undeclared name under a list additionalProperties' => ['x=1', ['type' => 'object', 'additionalProperties' => [['type' => 'integer']]], [], ['ok', ['x' => '1']]];
        yield 'an undeclared name repeated twice' => ['x=1&x=2', $open, [], ['ok', ['x' => ['1', '2']]]];
        yield 'an undeclared name repeated three times' => ['x=1&x=2&x=3', $open, [], ['ok', ['x' => ['1', '2', '3']]]];
        yield 'an undeclared name repeated and typed' => ['x=1&x=2&x=3', ['type' => 'object', 'additionalProperties' => ['type' => 'integer']], [], ['ok', ['x' => [1, 2, 3]]]];
        yield 'a declared scalar' => ['n=5', $typed, [], ['ok', ['n' => 5]]];
        yield 'a declared list' => ['tags=a&tags=b', $typed, [], ['ok', ['tags' => ['a', 'b']]]];
        yield 'a declared list that is not exploded' => ['tags=a,b', $typed, ['tags' => ['explode' => false]], ['ok', ['tags' => ['a', 'b']]]];
        yield 'a declared object, exploded' => ['k=1&j=2', $typed, [], ['ok', ['o' => ['k' => 1, 'j' => 2]]]];
        yield 'a declared object, not exploded' => ['o=k,1,j,2', $typed, ['o' => ['explode' => false]], ['ok', ['o' => ['k' => 1, 'j' => 2]]]];
        yield 'a pair consumed by a declared name is not read again' => ['tags=a&other=b', $typed, [], ['ok', ['tags' => ['a'], 'other' => 'b']]];
        yield 'a declared object, not exploded, that does not read as pairs' => ['o=k,1,j', $typed, ['o' => ['explode' => false]], [$failed, 'Form property "o" cannot be deserialized']];
        yield 'a declared object whose schema is unreadable' => [
            'o=k,1',
            ['type' => 'object', 'properties' => ['o' => ['type' => 'object', 'properties' => ['k' => 5]]]],
            ['o' => ['explode' => false]],
            [InvalidContract::class, 'Schema must be an object'],
        ];
        yield 'an encoding that is a string' => ['n=1', $typed, ['n' => 'x'], [$failed, 'Encoding for form property "n" must be an object']];
        yield 'an encoding that is a list' => ['n=1', $typed, ['n' => ['x']], [$failed, 'Encoding for form property "n" must be an object']];
        yield 'an encoding of another style' => ['n=1', $typed, ['n' => ['style' => 'deepObject']], [$failed, 'Encoding style "deepObject" is not supported for form property "n"']];
        yield 'an encoding whose style is not a string' => ['n=1', $typed, ['n' => ['style' => 5]], [$failed, 'Encoding style "int" is not supported for form property "n"']];
        yield 'an encoding with an explode that is not a boolean' => ['n=1', $typed, ['n' => ['explode' => 'no']], [$failed, 'Encoding explode for form property "n" must be a boolean']];
        yield 'an encoding with an empty content type' => ['n=1', $typed, ['n' => ['contentType' => '']], [$failed, 'Encoding contentType for form property "n" must be a non-empty string']];
        yield 'an encoding with a content type that is a number' => ['n=1', $typed, ['n' => ['contentType' => 5]], [$failed, 'Encoding contentType for form property "n" must be a non-empty string']];
        yield 'an encoding that says nothing leaves a list exploded' => ['tags=a&tags=b', $typed, ['tags' => ['style' => 'form']], ['ok', ['tags' => ['a', 'b']]]];
        yield 'a property with a JSON content type' => ['o=%7B%22k%22%3A1%7D', $typed, ['o' => ['contentType' => 'application/json']], ['ok', ['o' => ['k' => 1]]]];
        yield 'a property with a JSON content type that is not JSON' => ['o=%7Bnope', $typed, ['o' => ['contentType' => 'application/json']], [$failed, 'Form property "o" is not valid JSON']];
        yield 'a property with a JSON content type nested to the depth allowed' => ['o=' . str_repeat('%5B', 63) . str_repeat('%5D', 63), $typed, ['o' => ['contentType' => 'application/json']], ['ok', ['o' => self::nested(62)]]];
        yield 'a property with a JSON content type nested one level too deep' => ['o=' . str_repeat('%5B', 64) . str_repeat('%5D', 64), $typed, ['o' => ['contentType' => 'application/json']], [$failed, 'Form property "o" is not valid JSON']];
        yield 'a property with another content type stays a string' => ['n=abc', $typed, ['n' => ['contentType' => 'text/plain']], ['ok', ['n' => 'abc']]];
        yield 'a property with a content type twice' => ['n=1&n=2', $typed, ['n' => ['contentType' => 'text/plain']], [$failed, 'Form property "n" declares a content type and must occur exactly once']];
    }

    /** @return list<mixed> */
    private static function nested(int $levels): array
    {
        $value = [];
        for ($level = 0; $level < $levels; ++$level) {
            $value = [$value];
        }

        return $value;
    }

    /**
     * @param array{string, mixed} $expected
     */
    #[DataProvider('schemaValueProvider')]
    public function readsSchemaShapesTheWayTheDecoderDocuments(callable $call, array $expected): void
    {
        $actual = Refusal::quietly(static function () use ($call): array {
            try {
                return ['ok', $call(new SchemaValueDecoder())];
            } catch (\Throwable $exception) {
                return [$exception::class, $exception->getMessage()];
            }
        });

        Assert::same($actual, $expected);
    }

    /** @return iterable<string, array{callable, array{string, mixed}}> */
    public static function schemaValueProvider(): iterable
    {
        yield 'a schema that is not an array' => [static fn(SchemaValueDecoder $decoder): mixed => $decoder->schema('text'), [InvalidContract::class, 'Schema must be an object']];
        yield 'a schema that is a number' => [static fn(SchemaValueDecoder $decoder): mixed => $decoder->schema(5), [InvalidContract::class, 'Schema must be an object']];
        yield 'a schema that is a list' => [static fn(SchemaValueDecoder $decoder): mixed => $decoder->schema([1]), [InvalidContract::class, 'Schema must be an object']];
        yield 'a schema with a key that is a number' => [static fn(SchemaValueDecoder $decoder): mixed => $decoder->schema([5 => 'x']), [InvalidContract::class, 'Schema keys must be strings']];
        yield 'the empty schema' => [static fn(SchemaValueDecoder $decoder): mixed => $decoder->schema([]), ['ok', []]];
        yield 'a boolean schema' => [static fn(SchemaValueDecoder $decoder): mixed => $decoder->schema(value: true), ['ok', null]];
        yield 'no schema' => [static fn(SchemaValueDecoder $decoder): mixed => $decoder->schema(null), ['ok', null]];
        yield 'properties that are not a map' => [static fn(SchemaValueDecoder $decoder): mixed => $decoder->properties(['properties' => 'x']), ['ok', []]];
        yield 'no properties' => [static fn(SchemaValueDecoder $decoder): mixed => $decoder->properties([]), ['ok', []]];
        yield 'a null property is skipped and the ones after it are read' => [static fn(SchemaValueDecoder $decoder): mixed => $decoder->properties(['properties' => ['a' => null, 'b' => ['type' => 'integer'], 'c' => true]]), ['ok', ['b' => ['type' => 'integer'], 'c' => []]]];
        yield 'an object value skips what is not a string and goes on' => [static fn(SchemaValueDecoder $decoder): mixed => (array) $decoder->coerce(['a' => 'x', 'b' => ['y'], 'c' => '3'], ['properties' => ['c' => ['type' => 'integer']]]), ['ok', ['a' => 'x', 'c' => 3]]];
        yield 'a list value read by its items' => [static fn(SchemaValueDecoder $decoder): mixed => $decoder->coerce(['1', '2'], ['items' => ['type' => 'integer']]), ['ok', [1, 2]]];
        yield 'a scalar value' => [static fn(SchemaValueDecoder $decoder): mixed => $decoder->coerce('true', ['type' => 'boolean']), ['ok', true]];
    }
}
