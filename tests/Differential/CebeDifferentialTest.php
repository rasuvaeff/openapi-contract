<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests\Differential;

use cebe\openapi\exceptions\UnresolvableReferenceException;
use cebe\openapi\Reader;
use cebe\openapi\spec\OpenApi;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\InvalidContract;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Differential corpus for the hand-written multi-file resolver: the same
 * committed document trees are resolved by DocumentCompiler/JsonPointerResolver
 * and by cebe/php-openapi as an independent OAS 3.0 oracle (dev-only, never a
 * runtime dependency). Agreement is asserted where semantics overlap; every
 * deliberate divergence is pinned with the decision that explains it. OAS 3.1
 * is intentionally outside this oracle's coverage — 3.1 behavior keeps its own
 * executable fixtures.
 */
#[Test]
#[CoversNothing]
final class CebeDifferentialTest
{
    #[DataProvider('agreementCorpus')]
    public function resolvedParameterSchemasMatchCebe(string $case): void
    {
        $entry = $this->fixture($case);

        $ours = [];
        foreach (Contract::fromFile($entry)->operation('items.list')->parameters as $parameter) {
            $ours[] = [$parameter['name'], self::normalized($parameter['schema'])];
        }

        $cebe = [];
        $document = Reader::readFromJsonFile($entry, OpenApi::class, resolveReferences: true);
        foreach ($document->paths['/items']->get->parameters ?? [] as $parameter) {
            $schema = json_decode(json_encode($parameter->schema?->getSerializableData(), flags: JSON_THROW_ON_ERROR), associative: true);
            $cebe[] = [$parameter->name, self::normalized(is_array($schema) ? $schema : [])];
        }

        Assert::same($ours, $cebe);
    }

    /** @return iterable<string, array{string}> */
    public static function agreementCorpus(): iterable
    {
        yield 'sibling json file' => ['sibling-json'];
        yield 'nested relative refs' => ['nested-relative'];
        yield 'escaped pointer tokens' => ['escaped-pointer'];
        yield 'repeated reuse of one file' => ['reuse'];
        yield 'yaml sibling from json entry' => ['yaml-sibling'];
    }

    public function bothResolversRejectAMissingPointerTarget(): void
    {
        $entry = $this->fixture('missing-target');

        try {
            Contract::fromFile($entry);
            Assert::true(actual: false, message: 'Expected our resolver to reject the missing target');
        } catch (InvalidContract $exception) {
            Assert::string($exception->getMessage())->contains('Unresolvable $ref "schemas.json#/Nope"');
        }

        try {
            Reader::readFromJsonFile($entry, OpenApi::class, resolveReferences: true);
            Assert::true(actual: false, message: 'Expected cebe to reject the missing target');
        } catch (UnresolvableReferenceException) {
        }
    }

    public function rejectsCrossFileCyclesThatHangTheOracle(): void
    {
        // Pinned divergence: cebe/php-openapi does not terminate on this
        // cross-file cycle (verified 2026-08-31 — resolution loops until the
        // process is killed), so the oracle side is deliberately not executed.
        // Our depth budget turns the same document into a fast, stable error.
        try {
            Contract::fromFile($this->fixture('cycle'));
            Assert::true(actual: false, message: 'Expected a reference depth exception');
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI $ref chain is too deep (possible circular reference)');
        }
    }

    public function pinsTheDepthBudgetDivergenceOnDeepChains(): void
    {
        // Pinned divergence: cebe resolves this 41-link chain, we fail closed
        // at the 32-hop budget. The budget is the DoS guard the oracle lacks;
        // keeping the strict side is a deliberate decision, not a gap.
        $entry = $this->fixture('deep-chain');

        try {
            Contract::fromFile($entry);
            Assert::true(actual: false, message: 'Expected a reference depth exception');
        } catch (InvalidContract $exception) {
            Assert::same($exception->getMessage(), 'OpenAPI $ref chain is too deep (possible circular reference)');
        }

        $document = Reader::readFromJsonFile($entry, OpenApi::class, resolveReferences: true);
        $schema = json_decode(json_encode($document->paths['/items']->get->parameters[0]->schema?->getSerializableData(), flags: JSON_THROW_ON_ERROR), associative: true);
        Assert::same($schema, ['type' => 'integer']);
    }

    private function fixture(string $case): string
    {
        return __DIR__ . '/../fixtures/cebe-differential/' . $case . '/entry.json';
    }

    /** @param array<array-key, mixed> $value */
    private static function normalized(array $value): array
    {
        ksort($value);
        /** @var mixed $item */
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::normalized($item);
            }
        }

        return $value;
    }
}
