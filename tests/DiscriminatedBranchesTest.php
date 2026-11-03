<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Internal\Reference\JsonPointerResolver;
use Rasuvaeff\OpenApiContract\SchemaDialect;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(JsonPointerResolver::class)]
final class DiscriminatedBranchesTest
{
    private const array DOCUMENT = ['components' => ['schemas' => [
        'Cat' => ['type' => 'object', 'properties' => ['meow' => ['type' => 'string']]],
        'Name' => ['type' => 'string'],
        'Dog' => ['type' => 'object'],
    ]]];

    private const array UNION = [
        'oneOf' => [
            ['$ref' => '#/components/schemas/Cat', 'description' => 'cat', 'properties' => ['n' => ['$ref' => '#/components/schemas/Name']]],
            ['$ref' => '#/components/schemas/Dog'],
            'junk',
            ['type' => 'null'],
        ],
        'discriminator' => ['propertyName' => 'kind'],
    ];

    private const array DEFS = [
        'components.schemas.Cat' => ['type' => 'object', 'properties' => ['meow' => ['type' => 'string']]],
        'components.schemas.Dog' => ['type' => 'object'],
    ];

    public function keepsTheSiblingsOfAReferencedBranchUnderOas31AndResolvesThem(): void
    {
        $resolved = (new JsonPointerResolver(self::DOCUMENT, SchemaDialect::OpenApi31))->resolve(self::UNION, inSchema: true);

        Assert::same($resolved, [
            'oneOf' => [
                [
                    'allOf' => [
                        ['$ref' => '#/$defs/components.schemas.Cat', 'type' => 'object'],
                        ['properties' => ['n' => ['type' => 'string']]],
                    ],
                    'type' => 'object',
                    'description' => 'cat',
                ],
                ['$ref' => '#/$defs/components.schemas.Dog', 'type' => 'object'],
                'junk',
                ['type' => 'null'],
            ],
            'discriminator' => ['propertyName' => 'kind'],
            '$defs' => self::DEFS,
        ]);
    }

    public function dropsTheSiblingsOfAReferencedBranchUnderOas30(): void
    {
        $resolved = (new JsonPointerResolver(self::DOCUMENT, SchemaDialect::OpenApi30))->resolve(self::UNION, inSchema: true);

        Assert::same($resolved, [
            'oneOf' => [
                ['$ref' => '#/$defs/components.schemas.Cat', 'type' => 'object'],
                ['$ref' => '#/$defs/components.schemas.Dog', 'type' => 'object'],
                'junk',
                ['type' => 'null'],
            ],
            'discriminator' => ['propertyName' => 'kind'],
            '$defs' => self::DEFS,
        ]);
    }

    public function sharesAComponentAnotherSchemaAlreadyResolved(): void
    {
        $resolver = new JsonPointerResolver(self::DOCUMENT, SchemaDialect::OpenApi31);
        $resolver->resolve(['properties' => ['a' => ['$ref' => '#/components/schemas/Cat']]], inSchema: true);

        $resolved = $resolver->resolve(self::UNION, inSchema: true);

        Assert::same($resolved['$defs'], self::DEFS);
    }
}
