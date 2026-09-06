<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests;

use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * `Violation::$code` is documented as a stable identifier callers may switch
 * on, and the set of codes used to live nowhere but in the `sprintf`
 * arguments of two validators: a new code could appear, or an existing one be
 * renamed, without anyone noticing, and a caller who switches on codes had no
 * list to read.
 *
 * This pins the set. Adding or renaming a code is then a deliberate edit here
 * and in the three documents, not a side effect.
 */
#[Test]
#[CoversNothing]
final class ViolationCodeRegistryTest
{
    private const array CODES = [
        'request.body.decode',
        'request.body.json',
        'request.body.media_type',
        'request.body.missing',
        'request.body.non_seekable',
        'request.body.schema',
        'request.body.too_large',
        'request.body.unreadable',
        'request.body.unsupported',
        'request.operation.unknown',
        'request.parameter.missing',
        'request.parameter.schema',
        'request.parameter.serialization',
        'request.server.mismatch',
        'response.body.json',
        'response.body.media_type',
        'response.body.missing',
        'response.body.non_seekable',
        'response.body.schema',
        'response.body.too_large',
        'response.body.unreadable',
        'response.body.unsupported',
        'response.header.missing',
        'response.header.schema',
        'response.header.serialization',
        'response.header.unsupported',
        'response.operation.unknown',
        'response.status.invalid',
        'response.status.mismatch',
    ];

    public function emitsExactlyTheRegisteredCodes(): void
    {
        Assert::same($this->emittedCodes(), self::CODES);
    }

    #[DataProvider('documentProvider')]
    public function documentsEveryRegisteredCode(string $file): void
    {
        $contents = file_get_contents(__DIR__ . '/../' . $file);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('%s is unreadable', $file));
        }

        foreach (self::CODES as $code) {
            Assert::true(str_contains($contents, $code), message: sprintf('%s does not document "%s"', $file, $code));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function documentProvider(): iterable
    {
        yield 'README.md' => ['README.md'];
        yield 'README.ru.md' => ['README.ru.md'];
        yield 'llms.txt' => ['llms.txt'];
    }

    /** @return list<string> */
    private function emittedCodes(): array
    {
        $codes = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../src')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if (!is_string($contents)) {
                continue;
            }
            preg_match_all("/'((?:request|response)\.[a-z_]+\.[a-z_]+)'/", $contents, $matches);
            $codes = [...$codes, ...$matches[1]];
        }
        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }
}
