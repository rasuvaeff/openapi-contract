<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\OpenApiContract\Contract;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Contract::class)]
final class PathTemplateMatchingTest
{
    /**
     * @param array<string, string>|null $expected the captured path parameters, or null when nothing matches
     */
    #[DataProvider('matchProvider')]
    public function capturesTheParametersOfTheTemplateItMatches(string $template, string $requestPath, ?array $expected): void
    {
        preg_match_all('/\{([^{}]+)\}/', $template, $names);
        $parameters = array_map(
            static fn(string $name): array => ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
            $names[1],
        );
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => [$template => ['get' => ['operationId' => 'op', 'parameters' => $parameters, 'responses' => ['200' => ['description' => 'ok']]]]],
        ]);

        $matched = $contract->match(new ServerRequest('GET', $requestPath));

        Assert::same($matched?->pathParameters, $expected);
    }

    /** @return iterable<string, array{string, string, array<string, string>|null}> */
    public static function matchProvider(): iterable
    {
        yield 'a whole-segment placeholder' => ['/pets/{id}', '/pets/7', ['id' => '7']];
        yield 'a placeholder in the first segment' => ['/{tenant}/pets', '/acme/pets', ['tenant' => 'acme']];
        yield 'a literal first segment that differs' => ['/pets/{id}', '/cats/7', null];
        yield 'a literal and a placeholder in one segment' => ['/report.{format}', '/report.json', ['format' => 'json']];
        yield 'the literal of a mixed segment is not a pattern' => ['/report.{format}', '/reportXjson', null];
        yield 'the literal of a mixed segment is matched whole' => ['/v{version}/items', '/x1/items', null];
        yield 'two mixed segments keep both captures' => ['/v{version}/item-{id}', '/v2/item-9', ['version' => '2', 'id' => '9']];
        yield 'a mixed segment before a whole one keeps both captures' => ['/v{version}/{id}', '/v2/abc', ['version' => '2', 'id' => 'abc']];
        yield 'a whole segment before a mixed one keeps both captures' => ['/{id}/v{version}', '/abc/v2', ['id' => 'abc', 'version' => '2']];
        yield 'a mixed segment that does not match stops the walk' => ['/v{version}/{id}', '/w2/abc', null];
        yield 'two placeholders in one segment' => ['/{a}-{b}', '/x-y', ['a' => 'x', 'b' => 'y']];
        yield 'regex characters in a literal are literal' => ['/a+b/{id}', '/a+b/1', ['id' => '1']];
        yield 'regex characters in a mixed literal are literal' => ['/(x){id}', '/(x)1', ['id' => '1']];
        yield 'regex characters in a mixed literal never match as a pattern' => ['/(x){id}', '/x1', null];
        yield 'a trailing literal after a placeholder' => ['/{name}.json', '/data.json', ['name' => 'data']];
        yield 'the dot of a trailing literal is a dot' => ['/{name}.json', '/dataXjson', null];
        yield 'a trailing literal that is not there' => ['/{name}.json', '/data.xml', null];
        yield 'a different segment count' => ['/pets/{id}', '/pets', null];
        yield 'an encoded slash stays in its segment' => ['/pets/{id}', '/pets/a%2Fb', ['id' => 'a%2Fb']];
    }
}
