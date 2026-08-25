<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests\PhpUnit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OperationVisibilityTest extends TestCase
{
    #[DataProvider('operationProvider')]
    public function testEachOperationIsASeparateRunnerCase(string $operationId): void
    {
        self::assertNotSame('', $operationId);
    }

    /** @return iterable<string, array{string}> */
    public static function operationProvider(): iterable
    {
        yield 'pets.list' => ['pets.list'];
        yield 'pets.create' => ['pets.create'];
    }
}
