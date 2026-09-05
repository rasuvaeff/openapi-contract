<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests\Differential;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Violation;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Conformance replay of traffic this package did not write.
 *
 * The other differentials in this directory compare us with another library on
 * a corpus somebody typed out, which bounds what they can disagree about: every
 * finding of the last review — a boolean `additionalProperties` under 3.0, a
 * `+` read as a literal plus, a dropped integer-keyed property, an ignored
 * `encoding.contentType` — lived just outside those dozen requests.
 * `rasuvaeff/property-testing-openapi` generates requests from a document and
 * knows, by construction, whether each one is meant to pass. It depends on this
 * package, so it cannot be a dev dependency here; what it can do is record its
 * cases, and this replays the recording.
 *
 * What the corpus carries is the generator's intent, not our own verdict. A
 * corpus of "the validator said X" is a change detector that pins today's bugs
 * as expected behavior — every one of those four findings would have been
 * faithfully recorded as correct. `{"valid": true}` and `{"valid": false,
 * "misuse": {...}}` come from the side that built the bytes.
 *
 * The negative half asserts the location too. "Rejected" alone is nearly free
 * for a validator that fails closed in a dozen places, and a rejection for the
 * wrong reason is how a real regression hides: the case built to break `enum`
 * in the query must be reported against the query, not against a body we
 * mis-parsed on the way there.
 *
 * Checked against the version this all came out of rather than assumed:
 * replayed on 0.5.1's `src/`, the corpus refuses to compile the `bounded.create`
 * document at all (a boolean `additionalProperties` under 3.0) and rejects a
 * `numeric.create` body that was built valid (an integer-keyed property
 * dropped). What it does not catch there is the third finding of that wave —
 * an ignored `encoding.contentType` is fail-open, so every valid case still
 * passes, and seeing it needs a part whose media type is deliberately wrong.
 * The generator has no such misuse to build yet
 * (rasuvaeff/property-testing-openapi#80).
 *
 * Regenerate with `bin/record-openapi-corpus` from the monorepo root. Never
 * hand-edit it: a verdict that changed is a question about this package, and
 * re-recording it answers the question by deleting it.
 */
#[Test]
#[CoversNothing]
final class GeneratedCorpusTest
{
    /** @var array{contractVersionAtRecording?: string, documents: array<string, array<string, mixed>>, cases: list<array<string, mixed>>}|null */
    private static ?array $corpus = null;

    /** @var array<string, Contract> */
    private static array $contracts = [];

    #[DataProvider('recordedOperations')]
    public function recordedRequestsGetTheVerdictTheyWereBuiltFor(string $operationId): void
    {
        foreach (self::corpus()['cases'] as $case) {
            if ($case['operationId'] !== $operationId) {
                continue;
            }
            \assert(is_string($case['name']) && is_string($case['document']) && is_array($case['expect']));
            $result = $this->contract($case['document'])->validateRequest($this->request($case));
            $rendered = implode(', ', array_map(
                static fn(Violation $violation): string => $violation->code . '@' . $violation->location . $violation->instancePath,
                $result->violations,
            )) . $this->provenance();

            if ($case['expect']['valid'] === true) {
                Assert::true($result->isValid(), $case['name'] . ' was built valid but was rejected: ' . $rendered);

                continue;
            }

            \assert(is_array($case['expect']['misuse']));
            $location = $case['expect']['misuse']['location'];
            Assert::false($result->isValid(), $case['name'] . ' was built invalid but was accepted' . $this->provenance());
            $atLocation = array_filter(
                $result->violations,
                static fn(Violation $violation): bool => $violation->location === $location,
            );
            Assert::true(
                $atLocation !== [],
                $case['name'] . ' was rejected somewhere else than ' . $location . ': ' . $rendered,
            );
        }
    }

    /**
     * Says which version of this package the corpus was recorded against, so a
     * failure reads as "these verdicts were true at 0.6.0" rather than as an
     * unexplained disagreement. A verdict that moved since is a question about
     * this package — re-recording answers it by deleting it, which is only the
     * right answer once someone has looked.
     */
    private function provenance(): string
    {
        $version = self::corpus()['contractVersionAtRecording'] ?? 'unknown';

        return ' (corpus recorded against ' . (is_string($version) ? $version : 'unknown') . ')';
    }

    /**
     * Every recorded case belongs to an operation this provider yields, so a
     * corpus entry cannot go unreplayed by naming an operation nobody looks up.
     */
    public function theProviderCoversEveryRecordedCase(): void
    {
        $yielded = [];
        foreach (self::recordedOperations() as $dataset) {
            $yielded[] = $dataset[0];
        }
        $recorded = array_values(array_unique(array_map(
            static fn(array $case): string => (string) $case['operationId'],
            self::corpus()['cases'],
        )));
        sort($recorded);
        sort($yielded);

        Assert::same($yielded, $recorded);
        Assert::true(count(self::corpus()['cases']) > 100);
    }

    /** @return iterable<string, array{string}> */
    public static function recordedOperations(): iterable
    {
        $seen = [];
        foreach (self::corpus()['cases'] as $case) {
            $operationId = (string) $case['operationId'];
            if (isset($seen[$operationId])) {
                continue;
            }
            $seen[$operationId] = true;

            yield $operationId => [$operationId];
        }
    }

    /** @param array<string, mixed> $case */
    private function request(array $case): ServerRequestInterface
    {
        \assert(is_array($case['request']));
        $request = $case['request'];
        \assert(is_string($request['method']) && is_string($request['target']) && is_array($request['headers']));
        $factory = new Psr17Factory();
        $psr = $factory->createServerRequest($request['method'], $request['target']);
        foreach ($request['headers'] as $name => $values) {
            \assert(is_array($values));
            $psr = $psr->withHeader((string) $name, $values);
        }
        if (!is_array($request['body'])) {
            return $psr;
        }
        \assert(is_string($request['body']['content']));
        $content = $request['body']['encoding'] === 'base64'
            ? (string) base64_decode($request['body']['content'], strict: true)
            : $request['body']['content'];

        return $psr->withBody($factory->createStream($content));
    }

    private function contract(string $document): Contract
    {
        return self::$contracts[$document] ??= Contract::fromArray(self::corpus()['documents'][$document]);
    }

    /** @return array{contractVersionAtRecording?: string, documents: array<string, array<string, mixed>>, cases: list<array<string, mixed>>} */
    private static function corpus(): array
    {
        if (self::$corpus !== null) {
            return self::$corpus;
        }
        $raw = file_get_contents(dirname(__DIR__) . '/fixtures/generated-corpus/requests.json');
        \assert(is_string($raw));
        /** @var array{contractVersionAtRecording?: string, documents: array<string, array<string, mixed>>, cases: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

        return self::$corpus = $decoded;
    }
}
