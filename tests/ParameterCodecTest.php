<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterCodec;
use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterKind;
use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterStyle;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ParameterCodec::class)]
final class ParameterCodecTest
{
    #[DataProvider('examplesProvider')]
    public function serializesAndParsesSupportedShapes(
        string $name,
        string|array $value,
        ParameterStyle $style,
        bool $explode,
        ParameterKind $kind,
    ): void {
        $codec = new ParameterCodec();
        $wire = $codec->serialize($name, $value, $style, $explode);

        Assert::same($codec->parse($name, $wire, $style, $explode, $kind), $value);
    }

    /** @return iterable<string, array{string, string|array, ParameterStyle, bool, ParameterKind}> */
    public static function examplesProvider(): iterable
    {
        yield 'simple scalar' => ['id', 'a/b', ParameterStyle::Simple, false, ParameterKind::Scalar];
        yield 'simple list' => ['tags', ['red', 'blue'], ParameterStyle::Simple, true, ParameterKind::List];
        yield 'simple object' => ['user', ['role' => 'admin', 'name' => 'Ada'], ParameterStyle::Simple, true, ParameterKind::Object];
        yield 'label list' => ['tags', ['red', 'blue'], ParameterStyle::Label, true, ParameterKind::List];
        yield 'matrix list' => ['tags', ['red', 'blue'], ParameterStyle::Matrix, true, ParameterKind::List];
        yield 'matrix object without explode' => ['user', ['role' => 'admin', 'name' => 'Ada'], ParameterStyle::Matrix, false, ParameterKind::Object];
        yield 'form list' => ['tags', ['red', 'blue'], ParameterStyle::Form, true, ParameterKind::List];
        yield 'form list without explode' => ['tags', ['red', 'blue'], ParameterStyle::Form, false, ParameterKind::List];
        yield 'form object' => ['user', ['role' => 'admin', 'name' => 'Ada'], ParameterStyle::Form, true, ParameterKind::Object];
        yield 'form object without explode' => ['user', ['role' => 'admin', 'name' => 'Ada'], ParameterStyle::Form, false, ParameterKind::Object];
        yield 'space delimited list' => ['tags', ['red', 'blue'], ParameterStyle::SpaceDelimited, false, ParameterKind::List];
        yield 'pipe delimited list' => ['tags', ['red', 'blue'], ParameterStyle::PipeDelimited, false, ParameterKind::List];
        yield 'deep object' => ['user', ['role' => 'admin', 'name' => 'Ada'], ParameterStyle::DeepObject, true, ParameterKind::Object];
    }

    #[Property(runs: 80)]
    public function formListRoundTrip(array $value): void
    {
        $codec = new ParameterCodec();
        $wire = $codec->serialize('tags', $value, ParameterStyle::Form, explode: true);

        Assert::same($codec->parse('tags', $wire, ParameterStyle::Form, explode: true, kind: ParameterKind::List), $value);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function formListRoundTripGenerators(): array
    {
        return [
            'value' => Gen::nonEmptyArrayOf(Gen::stringFrom('abcXYZ', minLength: 1, maxLength: 4), maxSize: 5),
        ];
    }
}
