<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Limits;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Limits::class)]
final class LimitsTest
{
    public function defaultsToTheDocumentedBudgets(): void
    {
        $limits = new Limits();

        Assert::same($limits->documentBytes, 10 * 1024 * 1024);
        Assert::same($limits->messageBodyBytes, 1024 * 1024);
        Assert::same($limits->documentFiles, 64);
        Assert::same($limits->documentNodes, 5_000_000);
    }

    public function carriesTheBudgetsItWasGiven(): void
    {
        $limits = new Limits(documentBytes: 11, messageBodyBytes: 12, documentFiles: 13, documentNodes: 14);

        Assert::same($limits->documentBytes, 11);
        Assert::same($limits->messageBodyBytes, 12);
        Assert::same($limits->documentFiles, 13);
        Assert::same($limits->documentNodes, 14);
    }

    #[DataProvider('emptyBudgetProvider')]
    public function refusesABudgetThatAdmitsNothing(int $documentBytes, int $messageBodyBytes, int $documentFiles, int $documentNodes, string $message): void
    {
        try {
            new Limits(documentBytes: $documentBytes, messageBodyBytes: $messageBodyBytes, documentFiles: $documentFiles, documentNodes: $documentNodes);
            Assert::true(actual: false, message: 'Expected an empty budget to be refused');
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), $message);
        }
    }

    public static function emptyBudgetProvider(): iterable
    {
        yield 'zero document bytes' => [0, 1, 1, 1, 'Document byte budget must be positive'];
        yield 'negative document bytes' => [-1, 1, 1, 1, 'Document byte budget must be positive'];
        yield 'zero message body bytes' => [1, 0, 1, 1, 'Message body byte budget must be positive'];
        yield 'negative message body bytes' => [1, -1, 1, 1, 'Message body byte budget must be positive'];
        yield 'zero document files' => [1, 1, 0, 1, 'Document file budget must be positive'];
        yield 'negative document files' => [1, 1, -1, 1, 'Document file budget must be positive'];
        yield 'zero document nodes' => [1, 1, 1, 0, 'Document node budget must be positive'];
        yield 'negative document nodes' => [1, 1, 1, -1, 'Document node budget must be positive'];
    }
}
