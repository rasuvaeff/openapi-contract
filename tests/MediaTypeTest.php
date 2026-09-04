<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Internal\Validation\MediaType;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(MediaType::class)]
final class MediaTypeTest
{
    #[DataProvider('normalizeProvider')]
    public function normalizesTypeAndSubtype(string $value, string $expected): void
    {
        Assert::same(MediaType::normalize($value), $expected);
    }

    /** @return iterable<string, array{string, string}> */
    public static function normalizeProvider(): iterable
    {
        yield 'bare' => ['application/json', 'application/json'];
        yield 'parameters' => ['application/json; charset=utf-8', 'application/json'];
        yield 'mixed case' => ['Application/JSON', 'application/json'];
        yield 'surrounding whitespace' => ['  text/plain  ', 'text/plain'];
        yield 'empty' => ['', ''];
    }

    /**
     * `isJson()` normalizes its own argument, like the twin helper in
     * `property-testing-openapi`. The two used to disagree on that: this one
     * assumed a pre-normalized value, so the answer depended on which caller
     * had remembered to normalize first. Every call site happened to, which
     * is exactly how the same asymmetry in `mediaMatches()` stayed hidden
     * until it did not (0.5.1).
     */
    #[DataProvider('jsonProvider')]
    public function decidesJsonOnAnUnnormalizedValue(string $value, bool $expected): void
    {
        Assert::same(MediaType::isJson($value), $expected);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function jsonProvider(): iterable
    {
        yield 'json' => ['application/json', true];
        yield 'json with parameters' => ['application/json; charset=utf-8', true];
        yield 'json in mixed case' => ['Application/JSON', true];
        yield 'structured suffix' => ['application/problem+json', true];
        yield 'structured suffix with parameters' => ['application/vnd.api+json; version=1', true];
        yield 'text' => ['text/plain', false];
        yield 'json-ish subtype' => ['application/jsonish', false];
    }

    #[DataProvider('matchProvider')]
    public function matchesDeclarationsAgainstNormalizedActuals(string $declared, string $actual, bool $expected): void
    {
        Assert::same(MediaType::matches($declared, $actual), $expected);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function matchProvider(): iterable
    {
        yield 'exact' => ['application/json', 'application/json', true];
        yield 'declaration carries parameters' => ['application/json; charset=utf-8', 'application/json', true];
        yield 'declaration in mixed case' => ['Application/JSON', 'application/json', true];
        yield 'any' => ['*/*', 'image/png', true];
        yield 'subtype wildcard' => ['text/*', 'text/csv', true];
        yield 'json suffix wildcard' => ['application/*+json', 'application/problem+json', true];
        yield 'json suffix wildcard misses plain subtype' => ['application/*+json', 'application/json', false];
        yield 'type mismatch' => ['text/*', 'image/png', false];
        yield 'subtype mismatch' => ['text/plain', 'text/csv', false];
    }
}
