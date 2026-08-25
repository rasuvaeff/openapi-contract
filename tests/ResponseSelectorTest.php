<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use InvalidArgumentException;
use Rasuvaeff\OpenApiContract\Internal\Response\ResponseSelector;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ResponseSelector::class)]
final class ResponseSelectorTest
{
    #[DataProvider('precedenceProvider')]
    public function selectsByPrecedence(
        array $responses,
        int $status,
        ?string $expectedKey,
    ): void {
        $selected = (new ResponseSelector())->select($responses, $status);

        Assert::same($selected?->key, $expectedKey);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, int, ?string}>
     */
    public static function precedenceProvider(): iterable
    {
        yield 'exact beats range and default' => [
            [
                200 => ['description' => 'exact'],
                '2XX' => ['description' => 'range'],
                'default' => ['description' => 'default'],
            ],
            200,
            '200',
        ];
        yield 'range beats default' => [
            ['2XX' => ['description' => 'range'], 'default' => ['description' => 'default']],
            204,
            '2XX',
        ];
        yield 'default is fallback' => [
            ['default' => ['description' => 'default']],
            404,
            'default',
        ];
        yield 'missing response is explicit' => [[], 404, null];
    }

    public function rejectsInvalidStatus(): void
    {
        try {
            (new ResponseSelector())->select([], 99);
        } catch (InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), 'Invalid HTTP status 99');

            return;
        }

        Assert::true(actual: false, message: 'Expected invalid status exception');
    }
}
