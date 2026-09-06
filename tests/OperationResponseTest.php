<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Operation;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Operation::class)]
final class OperationResponseTest
{
    #[DataProvider('statusProvider')]
    public function resolvesTheResponseObjectLikeValidationDoes(int $status, ?string $key): void
    {
        $operation = $this->contract()->operation('pets.get');

        $selected = $operation->responseFor($status);

        Assert::same($selected['key'] ?? null, $key);
        if ($key !== null) {
            Assert::same($selected['definition'] ?? null, $operation->responses[$key]);
        }
    }

    public static function statusProvider(): iterable
    {
        yield 'exact' => [200, '200'];
        yield 'range' => [404, '4XX'];
        yield 'exact wins over range' => [400, '400'];
        yield 'default' => [503, 'default'];
    }

    public function returnsNullWithoutADeclaredResponse(): void
    {
        $operation = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/ping' => ['get' => ['operationId' => 'ping', 'responses' => ['204' => []]]]],
        ])->operation('ping');

        Assert::same($operation->responseFor(500), null);
    }

    public function answersNothingForAnImpossibleStatus(): void
    {
        // A status that is not an HTTP status is not declared either, and
        // `?array` is the answer this method already has for that. It used to
        // raise a bare `InvalidArgumentException` for 99 while answering
        // `null` for 418.
        Assert::null($this->contract()->operation('pets.get')->responseFor(99));
        Assert::null($this->contract()->operation('pets.get')->responseFor(600));
    }

    private function contract(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets' => ['get' => [
                'operationId' => 'pets.get',
                'responses' => [
                    '200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
                    '400' => ['description' => 'bad'],
                    '4XX' => ['description' => 'client'],
                    'default' => ['description' => 'other'],
                ],
            ]]],
        ]);
    }
}
