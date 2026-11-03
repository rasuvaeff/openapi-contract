<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaFailure;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaValidator;
use Rasuvaeff\OpenApiContract\SchemaDialect;
use Rasuvaeff\OpenApiContract\SchemaDirection;
use Rasuvaeff\OpenApiContract\Tests\Support\Refusal;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SchemaValidator::class)]
#[Covers(SchemaFailure::class)]
final class SchemaFailureReportTest
{
    public function twoBranchesFailingTheSameKeywordAtOnePathAreReportedOnceByTheFirst(): void
    {
        $schema = ['anyOf' => [['minimum' => 5], ['minimum' => 10]]];

        $failures = (new SchemaValidator())->failures(1, $schema, SchemaDialect::OpenApi31);

        Assert::same($this->render($failures), [['', 'minimum', ['minimum' => 5]]]);
    }

    public function sameKeywordAtDifferentPathsStaysSeparate(): void
    {
        $schema = ['properties' => ['a' => ['minimum' => 5], 'b' => ['minimum' => 5]]];

        $failures = (new SchemaValidator())->failures(json_decode('{"a":1,"b":2}'), $schema, SchemaDialect::OpenApi31);

        Assert::same($this->render($failures), [['a', 'minimum', ['minimum' => 5]], ['b', 'minimum', ['minimum' => 5]]]);
    }

    public function differentKeywordsAtOnePathStaySeparate(): void
    {
        $schema = ['minLength' => 5, 'pattern' => '^a'];

        $failures = (new SchemaValidator())->failures('x', $schema, SchemaDialect::OpenApi31);

        Assert::same(
            array_map(static fn(SchemaFailure $failure): string => $failure->keyword, $failures),
            ['minLength', 'pattern'],
        );
    }

    public function reportsAnUndeclaredMemberWhoseNameIsNumeric(): void
    {
        $failures = (new SchemaValidator())->failures(json_decode('{"1":"x"}'), ['type' => 'object', 'additionalProperties' => false], SchemaDialect::OpenApi31);

        Assert::same(
            array_map(static fn(SchemaFailure $failure): string => $failure->keyword, $failures),
            ['additionalProperties'],
        );
    }

    public function aMemberMatchedByAPatternIsNotAdditionalEvenWhenItFails(): void
    {
        $schema = ['type' => 'object', 'patternProperties' => ['^x-' => ['type' => 'integer']], 'additionalProperties' => false];

        $failures = (new SchemaValidator())->failures(json_decode('{"x-a":"s"}'), $schema, SchemaDialect::OpenApi31);

        Assert::same($this->render($failures), [['x-a', 'type', ['type' => 'integer']]]);
    }

    public function aMemberMatchedByNoPatternIsAdditionalNextToAFailingPatternMember(): void
    {
        $schema = ['type' => 'object', 'patternProperties' => ['^x-' => ['type' => 'integer']], 'additionalProperties' => false];

        $failures = (new SchemaValidator())->failures(json_decode('{"x-a":"s","y":1}'), $schema, SchemaDialect::OpenApi31);

        Assert::same(
            array_map(static fn(SchemaFailure $failure): string => $failure->keyword, $failures),
            ['type', 'additionalProperties'],
        );
    }

    public function aSecondPatternIsTriedWhenTheFirstDoesNotMatch(): void
    {
        $schema = ['type' => 'object', 'patternProperties' => ['^a-' => ['type' => 'string'], '^x-' => ['type' => 'integer']], 'additionalProperties' => false];

        $failures = (new SchemaValidator())->failures(json_decode('{"x-a":"s"}'), $schema, SchemaDialect::OpenApi31);

        Assert::same($this->render($failures), [['x-a', 'type', ['type' => 'integer']]]);
    }

    public function aNumericLookingPatternIsNeverReadAsARegex(): void
    {
        $schema = ['type' => 'object', 'patternProperties' => ['1' => ['type' => 'integer']], 'additionalProperties' => false];

        $failures = Refusal::quietly(static fn(): array => (new SchemaValidator())->failures(json_decode('{"x1":"s"}'), $schema, SchemaDialect::OpenApi31));

        Assert::same($failures[0]->keyword, 'type');
        Assert::same($failures[0]->path, ['x1']);
    }

    public function aNumericMemberNextToADeclaredOneIsStillAdditional(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'additionalProperties' => false];

        $failures = Refusal::quietly(static fn(): array => (new SchemaValidator())->failures(json_decode('{"a":1,"1":"x"}'), $schema, SchemaDialect::OpenApi31));

        Assert::same(
            array_map(static fn(SchemaFailure $failure): string => $failure->keyword, $failures),
            ['type', 'additionalProperties'],
        );
    }

    public function aNumericPatternDoesNotStopTheScanForTheNextOne(): void
    {
        $schema = ['type' => 'object', 'patternProperties' => ['1' => ['type' => 'string'], '^x-' => ['type' => 'integer']], 'additionalProperties' => false];

        $failures = (new SchemaValidator())->failures(json_decode('{"x-a":"s"}'), $schema, SchemaDialect::OpenApi31);

        Assert::same(
            array_map(static fn(SchemaFailure $failure): string => $failure->keyword, $failures),
            ['type'],
        );
    }

    public function anAssertionIsQuotedWithTheValueTheCompilerSpelled(): void
    {
        $failures = (new SchemaValidator())->failures('x', ['type' => 'integer', 'minimum' => 3], SchemaDialect::OpenApi31);

        Assert::same($failures[0]->expected, ['type' => 'integer']);
    }

    public function aDiscriminatorBesideAConjunctionIsNotAUnion(): void
    {
        $schema = [
            'allOf' => [['type' => 'object', 'required' => ['x']]],
            'discriminator' => ['propertyName' => 'kind'],
        ];

        $failures = Refusal::quietly(static fn(): array => (new SchemaValidator())->failures(json_decode('{"kind":"Zed"}'), $schema, SchemaDialect::OpenApi31));

        Assert::same($this->render($failures), [['x', 'required', ['required' => ['x']]]]);
    }

    public function aDiscriminatedUnionJudgesAScalarWithoutReadingItAsAnObject(): void
    {
        $schema = [
            'oneOf' => [['$ref' => '#/$defs/components.schemas.Cat', 'type' => 'object']],
            'discriminator' => ['propertyName' => 'kind'],
            '$defs' => ['components.schemas.Cat' => ['type' => 'object']],
        ];

        $failures = Refusal::quietly(static fn(): array => (new SchemaValidator())->failures(5, $schema, SchemaDialect::OpenApi31));

        Assert::same($this->render($failures), [['', 'type', ['type' => 'object']]]);
    }

    public function aDiscriminatedUnionJudgesAListWithoutReadingItAsAnObject(): void
    {
        $schema = [
            'oneOf' => [['$ref' => '#/$defs/components.schemas.Cat', 'type' => 'object']],
            'discriminator' => ['propertyName' => 'kind'],
            '$defs' => ['components.schemas.Cat' => ['type' => 'object']],
        ];

        $failures = Refusal::quietly(static fn(): array => (new SchemaValidator())->failures([1], $schema, SchemaDialect::OpenApi31));

        Assert::same($this->render($failures), [['', 'type', ['type' => 'object']]]);
    }

    public function theDirectionalRewriteReachesDefinitionsAndLeavesNonSchemaDefinitionsAlone(): void
    {
        $property = ['type' => 'integer', 'readOnly' => true];
        $schema = [
            '$defs' => [
                'flag' => true,
                'list' => [['type' => 'string']],
                'Pet' => ['required' => ['id', 'name'], 'properties' => ['id' => $property, 'name' => ['type' => 'string']]],
            ],
        ];

        $rewritten = Refusal::quietly(static fn(): array => (new SchemaValidator())->effectiveSchema($schema, SchemaDirection::Request));

        Assert::same($rewritten['$defs']['flag'], expected: true);
        Assert::same($rewritten['$defs']['list'], [['type' => 'string']]);
        Assert::same($rewritten['$defs']['Pet']['required'], ['name']);
        Assert::same($rewritten['$defs']['Pet']['properties']['id'], $property);
    }

    public function theDirectionalRewriteKeepsTheRequiredEntryOfAnUnflaggedProperty(): void
    {
        $schema = ['required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]];

        $rewritten = Refusal::quietly(static fn(): array => (new SchemaValidator())->effectiveSchema($schema, SchemaDirection::Request));

        Assert::same($rewritten, $schema);
    }

    public function theDirectionalRewriteDropsOnlyTheEntryOfAPropertyFlaggedForTheOtherDirection(): void
    {
        $schema = [
            'required' => ['id', 'secret', 'name'],
            'properties' => [
                'id' => ['readOnly' => true],
                'secret' => ['writeOnly' => true],
                'name' => ['readOnly' => false],
            ],
        ];

        $request = Refusal::quietly(static fn(): array => (new SchemaValidator())->effectiveSchema($schema, SchemaDirection::Request));
        $response = Refusal::quietly(static fn(): array => (new SchemaValidator())->effectiveSchema($schema, SchemaDirection::Response));

        Assert::same($request['required'], ['secret', 'name']);
        Assert::same($response['required'], ['id', 'name']);
    }

    /**
     * @param list<SchemaFailure> $failures
     * @return list<array{string, string, array<string, mixed>}>
     */
    private function render(array $failures): array
    {
        return array_map(
            static fn(SchemaFailure $failure): array => [implode('.', $failure->path), $failure->keyword, $failure->expected],
            $failures,
        );
    }
}
