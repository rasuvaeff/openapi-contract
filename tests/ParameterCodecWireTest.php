<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterCodec;
use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterKind;
use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterStyle;
use Rasuvaeff\OpenApiContract\Tests\Support\Refusal;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ParameterCodec::class)]
final class ParameterCodecWireTest
{
    /**
     * @param string|list<string>|array<string, string> $value
     */
    #[DataProvider('encodedProvider')]
    public function percentEncodesEveryItemOfAList(string|array $value, ParameterStyle $style, bool $explode, string $expected): void
    {
        Assert::same((new ParameterCodec())->serialize('x', $value, $style, $explode), $expected);
    }

    /** @return iterable<string, array{string|list<string>|array<string, string>, ParameterStyle, bool, string}> */
    public static function encodedProvider(): iterable
    {
        yield 'matrix list, not exploded' => [['a b', 'c d'], ParameterStyle::Matrix, false, ';x=a%20b,c%20d'];
        yield 'matrix list, exploded' => [['a b', 'c d'], ParameterStyle::Matrix, true, ';x=a%20b;x=c%20d'];
        yield 'form list, not exploded' => [['a b', 'c d'], ParameterStyle::Form, false, 'x=a%20b,c%20d'];
        yield 'form list, exploded' => [['a b', 'c d'], ParameterStyle::Form, true, 'x=a%20b&x=c%20d'];
        yield 'pipe delimited list' => [['a b', 'c/d'], ParameterStyle::PipeDelimited, false, 'x=a%20b|c%2Fd'];
        yield 'space delimited list' => [['a/b', 'c'], ParameterStyle::SpaceDelimited, false, 'x=a%2Fb%20c'];
        yield 'deepObject' => [['k' => 'a b', 'j' => 'c'], ParameterStyle::DeepObject, true, 'x%5Bk%5D=a%20b&x%5Bj%5D=c'];
        yield 'simple list' => [['a b', 'c'], ParameterStyle::Simple, false, 'a%20b,c'];
        yield 'label list, exploded' => [['a b', 'c'], ParameterStyle::Label, true, '.a%20b.c'];
    }

    public function trimsTheWhitespaceAroundEveryHeaderListItem(): void
    {
        Assert::same((new ParameterCodec())->withoutHeaderWhitespace(' a , b ,c'), 'a,b,c');
    }

    /**
     * @param array{string, mixed} $expected
     */
    #[DataProvider('parseProvider')]
    public function parsesOrRefusesWithTheReasonItGives(string $wire, ParameterStyle $style, bool $explode, ParameterKind $kind, array $expected): void
    {
        $actual = Refusal::quietly(static function () use ($wire, $style, $explode, $kind): array {
            try {
                return ['ok', (new ParameterCodec())->parse('q', $wire, $style, $explode, $kind)];
            } catch (\Throwable $exception) {
                return [$exception::class, $exception->getMessage()];
            }
        });

        Assert::same($actual, $expected);
    }

    /** @return iterable<string, array{string, ParameterStyle, bool, ParameterKind, array{string, mixed}}> */
    public static function parseProvider(): iterable
    {
        $bad = \InvalidArgumentException::class;

        yield 'a label without its dot' => ['a', ParameterStyle::Label, false, ParameterKind::Scalar, [$bad, 'Expected parameter fragment prefixed with "."']];
        yield 'a form scalar that names nothing asked for' => ['other=1', ParameterStyle::Form, false, ParameterKind::Scalar, [$bad, 'Parameter "q" is missing from serialized value']];
        yield 'a matrix list, exploded, that names nothing asked for' => [';other=1', ParameterStyle::Matrix, true, ParameterKind::List, [$bad, 'Parameter "q" is missing from serialized value']];
        yield 'a matrix scalar that names nothing asked for' => [';other=1', ParameterStyle::Matrix, false, ParameterKind::Scalar, [$bad, 'Parameter "q" is missing from serialized value']];
        yield 'an exploded simple object with a member without a value' => ['a', ParameterStyle::Simple, true, ParameterKind::Object, [$bad, 'Serialized object parameter contains an incomplete pair']];
        yield 'an exploded matrix object keeps equals signs in a value' => [';k=v=w', ParameterStyle::Matrix, true, ParameterKind::Object, ['ok', ['k' => 'v=w']]];
        yield 'an exploded simple object keeps equals signs in a value' => ['k=v=w,j=1', ParameterStyle::Simple, true, ParameterKind::Object, ['ok', ['k' => 'v=w', 'j' => '1']]];
        yield 'an unexploded object of four pieces' => ['a,1,b,2', ParameterStyle::Simple, false, ParameterKind::Object, ['ok', ['a' => '1', 'b' => '2']]];
        yield 'an unexploded object of six pieces' => ['a,1,b,2,c,3', ParameterStyle::Simple, false, ParameterKind::Object, ['ok', ['a' => '1', 'b' => '2', 'c' => '3']]];
        yield 'a query object with a pair that has no value' => ['flag&k=1', ParameterStyle::Form, true, ParameterKind::Object, ['ok', ['flag' => '', 'k' => '1']]];
        yield 'a delimited list that is not a list shape' => ['q=a|b', ParameterStyle::PipeDelimited, false, ParameterKind::Scalar, [$bad, 'Delimited query parameters require a list shape']];
        yield 'a delimited list that names nothing asked for' => ['other=a|b', ParameterStyle::PipeDelimited, false, ParameterKind::List, [$bad, 'Parameter "q" is missing from serialized value']];
        yield 'a delimited list with every spelling of the delimiter' => ['q=a|b%7Cc%7Cd', ParameterStyle::PipeDelimited, false, ParameterKind::List, ['ok', ['a', 'b', 'c', 'd']]];
    }
}
