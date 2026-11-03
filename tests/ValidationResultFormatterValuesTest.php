<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\ValidationResult;
use Rasuvaeff\OpenApiContract\ValidationResultFormatter;
use Rasuvaeff\OpenApiContract\Violation;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ValidationResultFormatter::class)]
final class ValidationResultFormatterValuesTest
{
    #[DataProvider('valueProvider')]
    public function rendersWhateverAViolationCarriesAsOneBoundedJsonValue(mixed $actual, string $expected): void
    {
        $text = (new ValidationResultFormatter())->format(new ValidationResult([new Violation(
            code: 'request.parameter.schema',
            operation: 'op',
            location: 'query',
            instancePath: 'page',
            specPointer: '/x',
            expected: null,
            actual: $actual,
            message: 'm',
        )]));

        Assert::string($text)->contains("   actual: " . $expected . "\n");
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function valueProvider(): iterable
    {
        $closed = fopen('php://memory', 'r');
        if ($closed === false) {
            throw new \RuntimeException('Unable to open a memory stream');
        }
        fclose($closed);
        $open = fopen('php://memory', 'r');
        if ($open === false) {
            throw new \RuntimeException('Unable to open a memory stream');
        }

        yield 'a decoded JSON object' => [(object) ['a' => 1, 'b' => (object) ['c' => true]], '{"a":1,"b":{"c":true}}'];
        yield 'an object of a class' => [new \ArrayObject(), '"[object ArrayObject]"'];
        yield 'an open resource' => [$open, '"[resource]"'];
        yield 'a closed resource' => [$closed, '"[unrenderable]"'];
        yield 'a closure' => [static fn(): int => 1, '"[object Closure]"'];
        yield 'a float with a zero fraction' => [1.0, '1.0'];
        yield 'a list' => [[1, 'a', null], '[1,"a",null]'];
    }
}
