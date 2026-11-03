<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Internal\Validation\BodyDecodingFailed;
use Rasuvaeff\OpenApiContract\Internal\Validation\MultipartBodyDecoder;
use Rasuvaeff\OpenApiContract\InvalidContract;
use Rasuvaeff\OpenApiContract\SchemaDialect;
use Rasuvaeff\OpenApiContract\Tests\Support\Refusal;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(MultipartBodyDecoder::class)]
final class MultipartBodyDecoderTest
{
    private const string TYPE = 'multipart/form-data; boundary=bnd';

    /**
     * @param array<string, mixed> $schema
     * @param array<array-key, mixed> $encoding
     * @param array{string, mixed} $expected `['ok', decoded body]` or `[exception class, message]`
     */
    #[DataProvider('decodingProvider')]
    public function decodesOrRefusesWithTheReasonItGives(string $body, string $contentType, array $schema, array $encoding, array $expected): void
    {
        $actual = Refusal::quietly(static function () use ($body, $contentType, $schema, $encoding): array {
            try {
                $decoded = (new MultipartBodyDecoder())->decode($body, $contentType, $schema, $encoding, SchemaDialect::OpenApi31);
            } catch (\Throwable $exception) {
                return [$exception::class, $exception->getMessage()];
            }

            return ['ok', json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), associative: true, flags: JSON_THROW_ON_ERROR)];
        });

        Assert::same($actual, $expected);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>, array<array-key, mixed>, array{string, mixed}}> */
    public static function decodingProvider(): iterable
    {
        $object = ['type' => 'object'];
        $text = static fn(string $name, string $value): array => [['Content-Disposition: form-data; name="' . $name . '"'], $value];
        $strings = ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']]];
        $tags = ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['type' => 'string']]]];
        $failed = BodyDecodingFailed::class;

        yield 'a schema that is not an object' => [self::body([$text('a', 'x')]), self::TYPE, ['type' => 'string'], [], [$failed, 'Multipart request bodies require an object schema']];
        yield 'a plain part' => [self::body([$text('a', 'x')]), self::TYPE, $strings, [], ['ok', ['a' => 'x']]];
        yield 'a part declared with an unquoted name' => [self::body([[['Content-Disposition: form-data; name=a'], 'x']]), self::TYPE, $strings, [], ['ok', ['a' => 'x']]];
        yield 'a boundary written in capitals of the parameter' => [self::body([$text('a', 'x')]), 'multipart/form-data; BOUNDARY=bnd', $strings, [], ['ok', ['a' => 'x']]];
        yield 'a quoted boundary' => [self::body([$text('a', 'x')]), 'multipart/form-data; boundary="bnd"', $strings, [], ['ok', ['a' => 'x']]];
        yield 'no boundary' => [self::body([$text('a', 'x')]), 'multipart/form-data', $strings, [], [$failed, 'Multipart request body has no boundary']];
        yield 'a boundary that is too long' => [self::body([$text('a', 'x')]), 'multipart/form-data; boundary=' . str_repeat('b', 71), $strings, [], [$failed, 'Multipart request boundary is invalid']];
        yield 'a boundary of the longest length' => [self::body([$text('a', 'x')], str_repeat('b', 70)), 'multipart/form-data; boundary=' . str_repeat('b', 70), $strings, [], ['ok', ['a' => 'x']]];
        yield 'a boundary with a forbidden character' => [self::body([$text('a', 'x')]), 'multipart/form-data; boundary="b<d"', $strings, [], [$failed, 'Multipart request boundary is invalid']];
        yield 'a body that closes at once' => ['--bnd--', self::TYPE, $strings, [], [$failed, 'Multipart request body has invalid boundary framing']];
        yield 'a body that opens without a line break' => ['--bnd x', self::TYPE, $strings, [], [$failed, 'Multipart request body has invalid boundary framing']];
        yield 'a closing delimiter with trailing garbage' => ["--bnd\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nx\r\n--bndjunk--", self::TYPE, $strings, [], [$failed, 'Multipart request body has an invalid closing boundary']];
        yield 'a closing delimiter that never closes' => ["--bnd\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nx\r\n--bnd", self::TYPE, $strings, [], [$failed, 'Multipart request body has an invalid closing boundary']];
        yield 'a closing delimiter with an epilogue' => ["--bnd\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nx\r\n--bnd--\r\nepilogue", self::TYPE, $strings, [], ['ok', ['a' => 'x']]];
        yield 'a closing delimiter without a line break' => ["--bnd\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nx\r\n--bnd--", self::TYPE, $strings, [], ['ok', ['a' => 'x']]];
        yield 'a part that does not follow its delimiter with a line break' => ["--bnd\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nx\r\n--bndX\r\n\r\n\r\n--bnd--", self::TYPE, $strings, [], [$failed, 'Multipart request part has invalid framing']];
        yield 'a part with no header terminator' => ["--bnd\r\nContent-Disposition: form-data; name=\"a\"\r\n--bnd--", self::TYPE, $strings, [], [$failed, 'Multipart request part has no header terminator']];
        yield 'the most parts allowed' => [self::body(self::numberedParts(128)), self::TYPE, $object, [], ['ok', self::numberedValues(128)]];
        yield 'one part too many' => [self::body(self::numberedParts(129)), self::TYPE, $object, [], [$failed, 'Multipart request body exceeds 128 parts']];
        yield 'part headers of the largest size' => [self::body([[['Content-Disposition: form-data; name="a"', 'X-Pad: ' . str_repeat('p', 16 * 1024 - strlen("Content-Disposition: form-data; name=\"a\"\r\nX-Pad: "))], 'x']]), self::TYPE, $strings, [], ['ok', ['a' => 'x']]];
        yield 'part headers one byte too large' => [self::body([[['Content-Disposition: form-data; name="a"', 'X-Pad: ' . str_repeat('p', 16 * 1024 + 1 - strlen("Content-Disposition: form-data; name=\"a\"\r\nX-Pad: "))], 'x']]), self::TYPE, $strings, [], [$failed, 'Multipart request part headers exceed 16384 bytes']];
        yield 'an invalid header line' => [self::body([[['Content-Disposition: form-data; name="a"', 'no colon here'], 'x']]), self::TYPE, $strings, [], [$failed, 'Multipart request part contains an invalid header']];
        yield 'a repeated header' => [self::body([[['Content-Disposition: form-data; name="a"', 'X-A: 1', 'x-a: 2'], 'x']]), self::TYPE, $strings, [], [$failed, 'Multipart request part repeats header "x-a"']];
        yield 'a part without a disposition' => [self::body([[['X-A: 1'], 'x']]), self::TYPE, $strings, [], [$failed, 'Multipart request part requires Content-Disposition form-data with a name']];
        yield 'a disposition that is not form-data' => [self::body([[['Content-Disposition: attachment; name="a"'], 'x']]), self::TYPE, $strings, [], [$failed, 'Multipart request part requires Content-Disposition form-data with a name']];
        yield 'a disposition that only contains form-data' => [self::body([[['Content-Disposition: x-form-data; name="a"'], 'x']]), self::TYPE, $strings, [], [$failed, 'Multipart request part requires Content-Disposition form-data with a name']];
        yield 'a disposition that continues form-data' => [self::body([[['Content-Disposition: form-datax; name="a"'], 'x']]), self::TYPE, $strings, [], [$failed, 'Multipart request part requires Content-Disposition form-data with a name']];
        yield 'a disposition in capitals' => [self::body([[['Content-Disposition: FORM-DATA; NAME="a"'], 'x']]), self::TYPE, $strings, [], ['ok', ['a' => 'x']]];
        yield 'a disposition without a name' => [self::body([[['Content-Disposition: form-data'], 'x']]), self::TYPE, $strings, [], [$failed, 'Multipart request part requires Content-Disposition form-data with a name']];
        yield 'a disposition with a trailing space' => [self::body([[['Content-Disposition: form-data; name="a"   '], 'x']]), self::TYPE, $strings, [], ['ok', ['a' => 'x']]];
        yield 'a part named like nothing declared falls back to additionalProperties' => [self::body([$text('n', '5')]), self::TYPE, ['type' => 'object', 'additionalProperties' => ['type' => 'integer']], [], ['ok', ['n' => 5]]];
        yield 'a part named like nothing declared under additionalProperties true' => [self::body([$text('n', '5')]), self::TYPE, ['type' => 'object', 'additionalProperties' => true], [], ['ok', ['n' => '5']]];
        yield 'a part named like nothing declared under no additionalProperties' => [self::body([$text('n', '5')]), self::TYPE, $object, [], ['ok', ['n' => '5']]];
        yield 'a scalar part twice' => [self::body([$text('a', 'x'), $text('a', 'y')]), self::TYPE, $strings, [], [$failed, 'Multipart property "a" occurs more than once']];
        yield 'an array part repeated' => [self::body([$text('tags', 'a,b'), $text('tags', 'c')]), self::TYPE, $tags, [], ['ok', ['tags' => ['a,b', 'c']]]];
        yield 'an array part left exploded by default keeps its commas' => [self::body([$text('tags', 'a,b')]), self::TYPE, $tags, [], ['ok', ['tags' => ['a,b']]]];
        yield 'an array part left exploded by an encoding that says nothing of it' => [self::body([$text('tags', 'a,b')]), self::TYPE, $tags, ['tags' => ['contentType' => 'text/plain']], ['ok', ['tags' => ['a,b']]]];
        yield 'an array part that is not exploded is split' => [self::body([$text('tags', 'a,b')]), self::TYPE, $tags, ['tags' => ['explode' => false]], ['ok', ['tags' => ['a', 'b']]]];
        yield 'a JSON array part that is not exploded is taken whole' => [self::body([[['Content-Disposition: form-data; name="tags"', 'Content-Type: application/json'], '["a","b"]']]), self::TYPE, $tags, ['tags' => ['explode' => false, 'contentType' => 'application/json']], ['ok', ['tags' => ['a', 'b']]]];
        yield 'a JSON array part with scalar items of every kind' => [self::body([[['Content-Disposition: form-data; name="v"', 'Content-Type: application/json'], '[1,1.5,true,null,[2],{"k":1},"s"]']]), self::TYPE, ['type' => 'object', 'properties' => ['v' => ['type' => 'array']]], ['v' => ['contentType' => 'application/json']], ['ok', ['v' => [1, 1.5, true, null, [2], ['k' => 1], 's']]]];
        yield 'an encoding that is a string' => [self::body([$text('a', 'x')]), self::TYPE, $strings, ['a' => 'text'], [$failed, 'Encoding for multipart property "a" must be an object']];
        yield 'an encoding that is a list' => [self::body([$text('a', 'x')]), self::TYPE, $strings, ['a' => ['text']], [$failed, 'Encoding for multipart property "a" must be an object']];
        yield 'an encoding with another style' => [self::body([$text('a', 'x')]), self::TYPE, $strings, ['a' => ['style' => 'deepObject']], [$failed, 'Encoding style for multipart property "a" must be form']];
        yield 'an encoding with an empty content type' => [self::body([$text('a', 'x')]), self::TYPE, $strings, ['a' => ['contentType' => '']], [$failed, 'Encoding contentType for multipart property "a" must be a non-empty string']];
        yield 'an encoding with a content type that is a number' => [self::body([$text('a', 'x')]), self::TYPE, $strings, ['a' => ['contentType' => 5]], [$failed, 'Encoding contentType for multipart property "a" must be a non-empty string']];
        yield 'an encoding with headers that are not an object' => [self::body([$text('a', 'x')]), self::TYPE, $strings, ['a' => ['headers' => 'x']], [$failed, 'Encoding headers for multipart property "a" must be an object']];
        yield 'an encoding with an explode that is not a boolean' => [self::body([$text('a', 'x')]), self::TYPE, $strings, ['a' => ['explode' => 'yes']], [$failed, 'Encoding explode for multipart property "a" must be a boolean']];
        yield 'a part of another content type than declared' => [self::body([[['Content-Disposition: form-data; name="a"', 'Content-Type: image/png'], 'x']]), self::TYPE, $strings, [], [$failed, 'Multipart property "a" has content type "image/png", expected "text/plain"']];
        yield 'a part of a declared content type in capitals' => [self::body([[['Content-Disposition: form-data; name="a"', 'Content-Type: image/png'], 'x']]), self::TYPE, $strings, ['a' => ['contentType' => 'IMAGE/PNG']], ['ok', ['a' => 'x']]];
        yield 'a part of one of several declared content types' => [self::body([[['Content-Disposition: form-data; name="a"', 'Content-Type: image/jpeg'], 'x']]), self::TYPE, $strings, ['a' => ['contentType' => 'image/png, image/jpeg']], ['ok', ['a' => 'x']]];
        yield 'a part of a content type with a parameter' => [self::body([[['Content-Disposition: form-data; name="a"', 'Content-Type: text/plain; charset=utf-8'], 'x']]), self::TYPE, $strings, [], ['ok', ['a' => 'x']]];
        yield 'a binary part' => [self::body([[['Content-Disposition: form-data; name="f"', 'Content-Type: application/octet-stream'], 'x']]), self::TYPE, ['type' => 'object', 'properties' => ['f' => ['type' => 'string', 'format' => 'binary']]], [], ['ok', ['f' => 'x']]];
        yield 'a JSON object part by default' => [self::body([[['Content-Disposition: form-data; name="o"', 'Content-Type: application/json'], '{"k":1}']]), self::TYPE, ['type' => 'object', 'properties' => ['o' => ['type' => 'object']]], [], ['ok', ['o' => ['k' => 1]]]];
        yield 'a JSON part nested to the depth allowed' => [self::body([[['Content-Disposition: form-data; name="o"', 'Content-Type: application/json'], str_repeat('[', 63) . str_repeat(']', 63)]]), self::TYPE, ['type' => 'object', 'properties' => ['o' => ['type' => 'array']]], ['o' => ['contentType' => 'application/json']], ['ok', ['o' => self::nested(62)]]];
        yield 'a JSON part nested one level too deep' => [self::body([[['Content-Disposition: form-data; name="o"', 'Content-Type: application/json'], str_repeat('[', 64) . str_repeat(']', 64)]]), self::TYPE, ['type' => 'object', 'properties' => ['o' => ['type' => 'array']]], ['o' => ['contentType' => 'application/json']], [$failed, 'Multipart property "o" is not valid JSON']];
        yield 'a JSON part that is not JSON' => [self::body([[['Content-Disposition: form-data; name="o"', 'Content-Type: application/json'], '{nope']]), self::TYPE, ['type' => 'object', 'properties' => ['o' => ['type' => 'object']]], [], [$failed, 'Multipart property "o" is not valid JSON']];
        yield 'a part header with surrounding space' => [self::body([[['Content-Disposition: form-data; name="a"', 'X-Count:   5   '], 'x']]), self::TYPE, $strings, ['a' => ['headers' => ['X-Count' => ['schema' => ['type' => 'integer']]]]], ['ok', ['a' => 'x']]];
        yield 'a declared Content-Type header is left to contentType' => [self::body([$text('a', 'x')]), self::TYPE, $strings, ['a' => ['headers' => ['Content-Type' => ['required' => true], 'X-Id' => ['required' => true]]]], [$failed, 'Multipart property "a" requires header "X-Id"']];
        yield 'a header missing and optional does not stop the next one' => [self::body([$text('a', 'x')]), self::TYPE, $strings, ['a' => ['headers' => ['X-Opt' => ['required' => false], 'X-Id' => ['required' => true]]]], [$failed, 'Multipart property "a" requires header "X-Id"']];
        yield 'a header declared by position' => [self::body([$text('a', 'x')]), self::TYPE, $strings, ['a' => ['headers' => [0 => ['required' => true], 'X-Id' => ['required' => true]]]], [$failed, 'Multipart property "a" requires header "X-Id"']];
        yield 'a required header present' => [self::body([[['Content-Disposition: form-data; name="a"', 'X-Id: 7'], 'x']]), self::TYPE, $strings, ['a' => ['headers' => ['X-Id' => ['required' => true]]]], ['ok', ['a' => 'x']]];
        yield 'a header declared by content' => [self::body([[['Content-Disposition: form-data; name="a"', 'X-Id: 7'], 'x']]), self::TYPE, $strings, ['a' => ['headers' => ['X-Id' => ['content' => ['text/plain' => []]]]]], [$failed, 'Multipart property "a" header "X-Id" uses unsupported content serialization']];
        yield 'a header declared with nothing to check' => [self::body([[['Content-Disposition: form-data; name="a"', 'X-Id: 7'], 'x']]), self::TYPE, $strings, ['a' => ['headers' => ['X-Id' => ['description' => 'free']]]], ['ok', ['a' => 'x']]];
        yield 'a header of another style' => [self::body([[['Content-Disposition: form-data; name="a"', 'X-Id: 7'], 'x']]), self::TYPE, $strings, ['a' => ['headers' => ['X-Id' => ['schema' => ['type' => 'integer'], 'style' => 'form']]]], [$failed, 'Multipart property "a" header "X-Id" uses an unsupported style']];
        yield 'a header that does not match its schema' => [self::body([[['Content-Disposition: form-data; name="a"', 'X-Id: seven'], 'x']]), self::TYPE, $strings, ['a' => ['headers' => ['X-Id' => ['schema' => ['type' => 'integer']]]]], [$failed, 'Multipart property "a" header "X-Id" does not match its schema']];
        yield 'a header list read without its whitespace' => [self::body([[['Content-Disposition: form-data; name="a"', 'X-Ids: 1, 2'], 'x']]), self::TYPE, $strings, ['a' => ['headers' => ['X-Ids' => ['schema' => ['type' => 'array', 'items' => ['type' => 'integer']]]]]], ['ok', ['a' => 'x']]];
        yield 'a header object that is not exploded' => [self::body([[['Content-Disposition: form-data; name="a"', 'X-Obj: k,1,j,2'], 'x']]), self::TYPE, $strings, ['a' => ['headers' => ['X-Obj' => ['schema' => ['type' => 'object', 'properties' => ['k' => ['type' => 'integer'], 'j' => ['type' => 'integer']]]]]]], ['ok', ['a' => 'x']]];
        yield 'a header object that is exploded' => [self::body([[['Content-Disposition: form-data; name="a"', 'X-Obj: k=1,j=2'], 'x']]), self::TYPE, $strings, ['a' => ['headers' => ['X-Obj' => ['explode' => true, 'schema' => ['type' => 'object', 'properties' => ['k' => ['type' => 'integer'], 'j' => ['type' => 'integer']]]]]]], ['ok', ['a' => 'x']]];
        yield 'a header object that does not read as pairs' => [self::body([[['Content-Disposition: form-data; name="a"', 'X-Obj: k,1,j'], 'x']]), self::TYPE, $strings, ['a' => ['headers' => ['X-Obj' => ['schema' => ['type' => 'object']]]]], [$failed, 'Multipart property "a" header "X-Obj" cannot be deserialized']];
        yield 'a header whose schema is unreadable' => [self::body([[['Content-Disposition: form-data; name="a"', 'X-Obj: k,1'], 'x']]), self::TYPE, $strings, ['a' => ['headers' => ['X-Obj' => ['schema' => ['type' => 'object', 'properties' => ['k' => 5]]]]]], [InvalidContract::class, 'Schema must be an object']];
    }

    /**
     * @param list<array{list<string>, string}> $parts
     */
    private static function body(array $parts, string $boundary = 'bnd'): string
    {
        $body = '';
        foreach ($parts as [$headers, $content]) {
            $body .= '--' . $boundary . "\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $content . "\r\n";
        }

        return $body . '--' . $boundary . "--\r\n";
    }

    /** @return list<array{list<string>, string}> */
    private static function numberedParts(int $count): array
    {
        $parts = [];
        for ($index = 0; $index < $count; ++$index) {
            $parts[] = [['Content-Disposition: form-data; name="p' . $index . '"'], 'v'];
        }

        return $parts;
    }

    /** @return array<string, string> */
    private static function numberedValues(int $count): array
    {
        $values = [];
        for ($index = 0; $index < $count; ++$index) {
            $values['p' . $index] = 'v';
        }

        return $values;
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
}
