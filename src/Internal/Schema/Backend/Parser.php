<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Schema\Backend;

use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Parsers\Vocabulary;

/**
 * The backend's parser knowing only the one draft this package compiles to,
 * in the form that carries the decimal `multipleOf`.
 *
 * @internal
 */
final class Parser extends SchemaParser
{
    #[\Override]
    protected function getDrafts(?Vocabulary $extraVocabulary): array
    {
        return ['2020-12' => new Draft($extraVocabulary)];
    }
}
