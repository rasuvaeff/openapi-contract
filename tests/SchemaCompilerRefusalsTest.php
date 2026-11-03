<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedDialect;
use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedSchema;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaCompiler;
use Rasuvaeff\OpenApiContract\SchemaDialect;
use Rasuvaeff\OpenApiContract\Tests\Support\Refusal;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(SchemaCompiler::class)]
final class SchemaCompilerRefusalsTest
{
    /**
     * @param array<string, mixed> $schema
     * @param class-string<\Throwable> $class
     */
    #[DataProvider('refusalProvider')]
    public function refusesAShapeItCannotCompileAndSaysWhy(SchemaDialect $dialect, array $schema, string $class, string $message): void
    {
        Assert::same(Refusal::of(static fn() => (new SchemaCompiler())->compile($schema, $dialect)), [$class, $message]);
    }

    /** @return iterable<string, array{SchemaDialect, array<string, mixed>, class-string<\Throwable>, string}> */
    public static function refusalProvider(): iterable
    {
        $schema = UnsupportedSchema::class;
        $v31 = SchemaDialect::OpenApi31;
        $v30 = SchemaDialect::OpenApi30;

        yield 'an $id' => [$v31, ['$id' => 'x'], $schema, 'Unsupported schema keyword "$id": reference identity is outside the support matrix'];
        yield 'an $anchor' => [$v31, ['$anchor' => 'x'], $schema, 'Unsupported schema keyword "$anchor": reference identity is outside the support matrix'];
        yield 'an allOf that is a string' => [$v31, ['allOf' => 'x'], $schema, 'Unsupported schema keyword "allOf": expected a list of schemas'];
        yield 'an allOf that is a map' => [$v31, ['allOf' => ['a' => []]], $schema, 'Unsupported schema keyword "allOf": expected a list of schemas'];
        yield 'properties that are a string' => [$v31, ['properties' => 'x'], $schema, 'Unsupported schema keyword "properties": expected an object of schemas'];
        yield 'dependentRequired that is a string' => [$v31, ['dependentRequired' => 'x'], $schema, 'Unsupported schema keyword "dependentRequired": expected an object of member name to required member names'];
        yield 'dependentRequired with a member that is not a name' => [$v31, ['dependentRequired' => ['a' => [5]]], $schema, 'Unsupported schema keyword "dependentRequired": a member required by "a" must be a name'];
        yield 'dependentRequired with a repeated member' => [$v31, ['dependentRequired' => ['a' => ['b', 'b']]], $schema, 'Unsupported schema keyword "dependentRequired": the members required by "a" must be unique'];
        yield 'dependentRequired with members that are not a list' => [$v31, ['dependentRequired' => ['a' => 'b']], $schema, 'Unsupported schema keyword "dependentRequired": the members required by "a" must be a list of names'];
        yield 'a $schema under 3.0' => [$v30, ['$schema' => 'urn:x'], UnsupportedDialect::class, 'Unsupported JSON Schema dialect "urn:x"'];
        yield 'a $schema under 3.0 that is not a string' => [$v30, ['$schema' => 5], UnsupportedDialect::class, 'Unsupported JSON Schema dialect "int"'];
        yield 'a nullable that is not a boolean' => [$v30, ['nullable' => 'yes'], $schema, 'Unsupported schema keyword "nullable": expected a boolean'];
        yield 'a nullable beside a union of types' => [$v30, ['nullable' => true, 'type' => ['string', 'integer']], $schema, 'Unsupported schema keyword "type": OAS 3.0 requires a single type'];
        yield 'an exclusiveMinimum that is not a boolean' => [$v30, ['exclusiveMinimum' => 5, 'minimum' => 1], $schema, 'Unsupported schema keyword "exclusiveMinimum": OAS 3.0 requires a boolean'];
        yield 'an exclusiveMinimum without a minimum' => [$v30, ['exclusiveMinimum' => true], $schema, 'Unsupported schema keyword "exclusiveMinimum": requires numeric minimum'];
        yield 'an exclusiveMaximum over a maximum that is not a number' => [$v30, ['exclusiveMaximum' => true, 'maximum' => 'x'], $schema, 'Unsupported schema keyword "exclusiveMaximum": requires numeric maximum'];
        yield 'an exclusiveMaximum beside another bound only' => [$v30, ['exclusiveMaximum' => true, 'minimum' => 2], $schema, 'Unsupported schema keyword "exclusiveMaximum": requires numeric maximum'];
        yield 'items that are a string under 3.0' => [$v30, ['items' => 'x'], $schema, 'Unsupported schema keyword "items": expected a schema object'];
        yield 'items that are a list under 3.0' => [$v30, ['items' => [1]], $schema, 'Unsupported schema keyword "items": expected a schema object'];
        yield 'items that are a boolean under 3.0' => [$v30, ['items' => true], $schema, 'Unsupported schema keyword "items": boolean schemas require OAS 3.1'];
        yield 'items that are a string under 3.1' => [$v31, ['items' => 'x'], $schema, 'Unsupported schema keyword "items": expected a schema object'];
        yield 'items that are a list under 3.1' => [$v31, ['items' => [1]], $schema, 'Unsupported schema keyword "items": expected a schema object'];
        yield 'a not that is a string' => [$v31, ['not' => 'x'], $schema, 'Unsupported schema keyword "not": expected a schema object'];
    }

    public function keepsAnExclusiveBoundAsTheBoundItReplaces(): void
    {
        $compiler = new SchemaCompiler();

        Assert::same(json_decode(json_encode($compiler->compile(['exclusiveMaximum' => true, 'maximum' => 3], SchemaDialect::OpenApi30)), associative: true)['exclusiveMaximum'] ?? null, 3);
        Assert::same(json_decode(json_encode($compiler->compile(['exclusiveMinimum' => true, 'minimum' => 3], SchemaDialect::OpenApi30)), associative: true)['exclusiveMinimum'] ?? null, 3);
        $plain = json_decode(json_encode($compiler->compile(['exclusiveMaximum' => false, 'maximum' => 3], SchemaDialect::OpenApi30)), associative: true);
        Assert::same($plain['maximum'] ?? null, 3);
        Assert::false(array_key_exists('exclusiveMaximum', $plain));
    }

    public function keepsTheOrderAndTheNamesOfASchemaMap(): void
    {
        $compiled = json_decode(json_encode((new SchemaCompiler())->compile(
            ['properties' => ['b' => ['type' => 'string'], 'a' => ['type' => 'integer']], 'dependentRequired' => ['b' => ['a']]],
            SchemaDialect::OpenApi31,
        )), associative: true);

        Assert::same(array_keys($compiled['properties']), ['b', 'a']);
        Assert::same($compiled['dependentRequired'], ['b' => ['a']]);
    }
}
