<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use InvalidArgumentException;
use Rasuvaeff\OpenApiContract\Internal\Response\ResponseSelector;
use Rasuvaeff\OpenApiContract\Internal\Response\SelectedResponse;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ResponseSelector::class)]
#[Covers(SelectedResponse::class)]
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
        // A document writing the range in lowercase names the same range;
        // failing to match it reports "status not declared", which this
        // package answers by validating nothing at all.
        yield 'a lowercase range matches' => [
            ['2xx' => ['description' => 'range'], 'default' => ['description' => 'default']],
            204,
            '2xx',
        ];
        yield 'a mixed-case range matches' => [
            ['4Xx' => ['description' => 'range']],
            404,
            '4Xx',
        ];
        yield 'exact status still beats a lowercase range' => [
            [204 => ['description' => 'exact'], '2xx' => ['description' => 'range']],
            204,
            '204',
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
        yield 'exact numeric key beats string range' => [['200' => ['description' => 'exact'], '2XX' => ['description' => 'range']], 200, '200'];
    }

    public function selectsNothingForAStatusThatIsNotOne(): void
    {
        // Not an exception: the two callers want different answers to this —
        // `Operation::responseFor()` says "not declared", response validation
        // reports the response as wrong — and neither is served by raising
        // here, least of all by a bare `InvalidArgumentException`.
        $responses = ['default' => ['description' => 'any']];

        Assert::null((new ResponseSelector())->select($responses, 99));
        Assert::null((new ResponseSelector())->select($responses, 600));
        Assert::same((new ResponseSelector())->select($responses, 100)?->key, 'default');
    }

    public function acceptsBoundaryStatusesAndComputesExactRanges(): void
    {
        $selector = new ResponseSelector();

        Assert::same($selector->select(['1XX' => ['description' => 'r']], 100)?->key, '1XX');
        Assert::same($selector->select(['1XX' => ['description' => 'r']], 199)?->key, '1XX');
        Assert::same($selector->select(['5XX' => ['description' => 'r']], 599)?->key, '5XX');
    }
}
