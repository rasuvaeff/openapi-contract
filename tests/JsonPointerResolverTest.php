<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Internal\Exception\UnsupportedReference;
use Rasuvaeff\OpenApiContract\Internal\Reference\JsonPointerResolver;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(JsonPointerResolver::class)]
final class JsonPointerResolverTest
{
    public function resolvesEscapedSameDocumentReferences(): void
    {
        $resolver = new JsonPointerResolver([
            'components' => [
                'schemas' => [
                    'name/with~characters' => ['type' => 'string'],
                ],
            ],
        ]);

        Assert::same(
            $resolver->resolve(['$ref' => '#/components/schemas/name~1with~0characters']),
            ['type' => 'string'],
        );
    }

    #[DataProvider('unsupportedReferenceProvider')]
    public function rejectsReferencesOutsideTheLocalFragmentBoundary(mixed $reference): void
    {
        try {
            (new JsonPointerResolver([]))->resolve(['$ref' => $reference]);
        } catch (UnsupportedReference $exception) {
            Assert::string($exception->getMessage())->contains('same-document JSON Pointer');

            return;
        }

        Assert::true(actual: false, message: 'Expected unsupported reference exception');
    }

    /** @return iterable<string, array{mixed}> */
    public static function unsupportedReferenceProvider(): iterable
    {
        yield 'remote URL' => ['https://example.test/schema.json'];
        yield 'local file' => ['schema.json#/value'];
        yield 'non-string reference' => [42];
    }

    public function rejectsCircularReferencesAtTheConfiguredDepth(): void
    {
        $resolver = new JsonPointerResolver(
            document: ['components' => ['schemas' => ['loop' => ['$ref' => '#/components/schemas/loop']]]],
            maximumReferenceDepth: 2,
        );

        try {
            $resolver->resolve(['$ref' => '#/components/schemas/loop']);
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI $ref chain is too deep (possible circular reference)');

            return;
        }

        Assert::true(actual: false, message: 'Expected reference depth exception');
    }

    public function rejectsDocumentsThatExhaustTheSharedNodeBudget(): void
    {
        $resolver = new JsonPointerResolver(document: [], maximumResolvedNodes: 2);

        try {
            $resolver->resolve(['first' => ['second' => []]]);
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI document exceeds the reference-resolution budget of 2 nodes');

            return;
        }

        Assert::true(actual: false, message: 'Expected reference-resolution budget exception');
    }
}
