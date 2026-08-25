<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Response;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaDialect;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaValidator;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;

#[Test]
#[CoversNothing]
final class BackendFeasibilityTest
{
    public function opisIsStrictWhereLeagueIsFailOpen(): void
    {
        $schema = [
            'type' => ['integer', 'null'],
            'exclusiveMinimum' => 2,
            'const' => 3,
        ];
        $opis = new SchemaValidator();

        Assert::true($opis->isValid(3, $schema, SchemaDialect::OpenApi31));
        Assert::false($opis->isValid(2, $schema, SchemaDialect::OpenApi31));
        Assert::false($opis->isValid(4, $schema, SchemaDialect::OpenApi31));

        $league = (new ValidatorBuilder())
            ->fromJson(json_encode([
                'openapi' => '3.1.0',
                'info' => ['title' => 'feasibility', 'version' => '1'],
                'paths' => [
                    '/value' => [
                        'get' => [
                            'responses' => [
                                '200' => [
                                    'description' => 'ok',
                                    'content' => ['application/json' => ['schema' => $schema]],
                                ],
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR))
            ->getResponseValidator();

        Assert::true($this->leagueAccepts($league, '2'));
        Assert::true($this->leagueAccepts($league, '3'));
        Assert::true($this->leagueAccepts($league, '4'));
    }

    private function leagueAccepts(
        ResponseValidator $validator,
        string $body,
    ): bool {
        try {
            $validator->validate(
                new OperationAddress('/value', 'get'),
                new Response(200, ['Content-Type' => 'application/json'], $body),
            );
        } catch (\Throwable) {
            return false;
        }

        return true;
    }
}
