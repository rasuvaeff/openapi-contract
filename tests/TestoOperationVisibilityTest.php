<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[CoversNothing]
final class TestoOperationVisibilityTest
{
    #[DataProvider('operationProvider')]
    public function eachOperationIsASeparateRunnerCase(string $operationId): void
    {
        Assert::true($operationId !== '');
    }

    /** @return iterable<string, array{string}> */
    public static function operationProvider(): iterable
    {
        yield 'pets.list' => ['pets.list'];
        yield 'pets.create' => ['pets.create'];
    }
}
