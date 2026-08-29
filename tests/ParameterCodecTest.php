<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterCodec;
use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterKind;
use Rasuvaeff\OpenApiContract\Internal\Serialization\ParameterStyle;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
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

    #[Property(runs: 1000)]
    public function everySupportedStyleAndExplodeCombinationRoundTrips(array $case): void
    {
        $codec = new ParameterCodec();
        $wire = $codec->serialize(
            name: $case['name'],
            value: $case['value'],
            style: $case['style'],
            explode: $case['explode'],
        );

        Classify::cover(condition: $case['label'] === 'simple scalar', label: 'simple scalar', minPercent: 3.0);
        Classify::cover(condition: $case['label'] === 'simple list exploded', label: 'simple list exploded', minPercent: 3.0);
        Classify::cover(condition: $case['label'] === 'simple list unexploded', label: 'simple list unexploded', minPercent: 3.0);
        Classify::cover(condition: $case['label'] === 'simple object exploded', label: 'simple object exploded', minPercent: 3.0);
        Classify::cover(condition: $case['label'] === 'simple object unexploded', label: 'simple object unexploded', minPercent: 3.0);
        Classify::cover(condition: $case['label'] === 'label list exploded', label: 'label list exploded', minPercent: 3.0);
        Classify::cover(condition: $case['label'] === 'label list unexploded', label: 'label list unexploded', minPercent: 3.0);
        Classify::cover(condition: $case['label'] === 'matrix list exploded', label: 'matrix list exploded', minPercent: 3.0);
        Classify::cover(condition: $case['label'] === 'matrix list unexploded', label: 'matrix list unexploded', minPercent: 3.0);
        Classify::cover(condition: $case['label'] === 'matrix object exploded', label: 'matrix object exploded', minPercent: 3.0);
        Classify::cover(condition: $case['label'] === 'matrix object unexploded', label: 'matrix object unexploded', minPercent: 3.0);
        Classify::cover(condition: $case['label'] === 'form list exploded', label: 'form list exploded', minPercent: 3.0);
        Classify::cover(condition: $case['label'] === 'form list unexploded', label: 'form list unexploded', minPercent: 3.0);
        Classify::cover(condition: $case['label'] === 'form object exploded', label: 'form object exploded', minPercent: 3.0);
        Classify::cover(condition: $case['label'] === 'form object unexploded', label: 'form object unexploded', minPercent: 3.0);
        Classify::cover(condition: $case['label'] === 'space delimited list', label: 'space delimited list', minPercent: 3.0);
        Classify::cover(condition: $case['label'] === 'pipe delimited list', label: 'pipe delimited list', minPercent: 3.0);
        Classify::cover(condition: $case['label'] === 'deep object', label: 'deep object', minPercent: 3.0);
        Assert::same(
            $codec->parse(
                name: $case['name'],
                wire: $wire,
                style: $case['style'],
                explode: $case['explode'],
                kind: $case['kind'],
            ),
            $case['value'],
        );
    }

    /** @return array<string, ArbitraryInterface> */
    public static function everySupportedStyleAndExplodeCombinationRoundTripsGenerators(): array
    {
        $scalar = Gen::stringFrom('abcXYZ', minLength: 1, maxLength: 4);
        $list = Gen::nonEmptyArrayOf($scalar, maxSize: 4);
        $object = Gen::record(['role' => $scalar, 'name' => $scalar]);

        return [
            'case' => Gen::frequency([
                [1, self::case('simple scalar', $scalar, ParameterStyle::Simple, explode: false, kind: ParameterKind::Scalar)],
                [1, self::case('simple list exploded', $list, ParameterStyle::Simple, explode: true, kind: ParameterKind::List)],
                [1, self::case('simple list unexploded', $list, ParameterStyle::Simple, explode: false, kind: ParameterKind::List)],
                [1, self::case('simple object exploded', $object, ParameterStyle::Simple, explode: true, kind: ParameterKind::Object)],
                [1, self::case('simple object unexploded', $object, ParameterStyle::Simple, explode: false, kind: ParameterKind::Object)],
                [1, self::case('label list exploded', $list, ParameterStyle::Label, explode: true, kind: ParameterKind::List)],
                [1, self::case('label list unexploded', $list, ParameterStyle::Label, explode: false, kind: ParameterKind::List)],
                [1, self::case('matrix list exploded', $list, ParameterStyle::Matrix, explode: true, kind: ParameterKind::List)],
                [1, self::case('matrix list unexploded', $list, ParameterStyle::Matrix, explode: false, kind: ParameterKind::List)],
                [1, self::case('matrix object exploded', $object, ParameterStyle::Matrix, explode: true, kind: ParameterKind::Object)],
                [1, self::case('matrix object unexploded', $object, ParameterStyle::Matrix, explode: false, kind: ParameterKind::Object)],
                [1, self::case('form list exploded', $list, ParameterStyle::Form, explode: true, kind: ParameterKind::List)],
                [1, self::case('form list unexploded', $list, ParameterStyle::Form, explode: false, kind: ParameterKind::List)],
                [1, self::case('form object exploded', $object, ParameterStyle::Form, explode: true, kind: ParameterKind::Object)],
                [1, self::case('form object unexploded', $object, ParameterStyle::Form, explode: false, kind: ParameterKind::Object)],
                [1, self::case('space delimited list', $list, ParameterStyle::SpaceDelimited, explode: false, kind: ParameterKind::List)],
                [1, self::case('pipe delimited list', $list, ParameterStyle::PipeDelimited, explode: false, kind: ParameterKind::List)],
                [1, self::case('deep object', $object, ParameterStyle::DeepObject, explode: true, kind: ParameterKind::Object)],
            ]),
        ];
    }

    public function serializesExactWireFormats(): void
    {
        $codec = new ParameterCodec();

        Assert::same($codec->serialize('u', ['role' => 'admin', 'name' => 'Ada'], ParameterStyle::Label, explode: true), '.role=admin.name=Ada');
        Assert::same($codec->serialize('u', ['role' => 'admin', 'name' => 'Ada'], ParameterStyle::Matrix, explode: false), ';u=role,admin,name,Ada');
        Assert::same($codec->serialize('t', ['a b', 'c'], ParameterStyle::Simple, explode: false), 'a%20b,c');
        Assert::same($codec->serialize('t', ['a b', 'c'], ParameterStyle::Form, explode: false), 't=a%20b,c');
        Assert::same($codec->serialize('t', ['a b', 'c'], ParameterStyle::SpaceDelimited, explode: false), 't=a%20b c');
    }

    #[DataProvider('invalidValueShapes')]
    public function rejectsInvalidValueShapesWithExactMessages(string|array $value, ParameterStyle $style, string $message): void
    {
        try {
            (new ParameterCodec())->serialize('u', $value, $style, explode: false);
            Assert::true(actual: false, message: 'Expected invalid value shape exception');
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), $message);
        }
    }

    /** @return iterable<string, array{string|array<array-key, string>, ParameterStyle, string}> */
    public static function invalidValueShapes(): iterable
    {
        yield 'delimited assoc value' => [['k' => 'v'], ParameterStyle::SpaceDelimited, 'Parameter requires a list value'];
        yield 'delimited non-string item' => [[1], ParameterStyle::PipeDelimited, 'Parameter values must be strings'];
        yield 'deep object list value' => [['a', 'b'], ParameterStyle::DeepObject, 'Parameter requires an object value'];
        yield 'deep object int key' => [[0 => 'a', 'k' => 'b'], ParameterStyle::DeepObject, 'Parameter object keys and values must be strings'];
    }

    public function parseToleratesRepeatedMatrixSeparators(): void
    {
        $parsed = (new ParameterCodec())->parse('u', ';role=admin;;name=Ada', ParameterStyle::Matrix, explode: true, kind: ParameterKind::Object);

        Assert::same($parsed, ['role' => 'admin', 'name' => 'Ada']);
    }

    public function parseKeepsEqualsSignsInsideDeepObjectValues(): void
    {
        $parsed = (new ParameterCodec())->parse('u', 'u%5Brole%5D=x=y', ParameterStyle::DeepObject, explode: true, kind: ParameterKind::Object);

        Assert::same($parsed, ['role' => 'x=y']);
    }

    public function parseRejectsMalformedObjectWires(): void
    {
        $codec = new ParameterCodec();

        try {
            $codec->parse('u', 'role,admin,extra', ParameterStyle::Simple, explode: false, kind: ParameterKind::Object);
            Assert::true(actual: false, message: 'Expected incomplete pair exception');
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), 'Serialized object parameter contains an incomplete pair');
        }

        try {
            $codec->parse('u', 'u%5Brole=admin', ParameterStyle::DeepObject, explode: true, kind: ParameterKind::Object);
            Assert::true(actual: false, message: 'Expected invalid deepObject exception');
        } catch (\InvalidArgumentException $exception) {
            Assert::same($exception->getMessage(), 'Invalid deepObject parameter');
        }
    }

    private static function case(
        string $label,
        ArbitraryInterface $value,
        ParameterStyle $style,
        bool $explode,
        ParameterKind $kind,
    ): ArbitraryInterface {
        return Gen::map($value, static fn(string|array $value): array => [
            'label' => $label,
            'name' => $kind === ParameterKind::Object ? 'user' : 'tags',
            'value' => $value,
            'style' => $style,
            'explode' => $explode,
            'kind' => $kind,
        ]);
    }
}
