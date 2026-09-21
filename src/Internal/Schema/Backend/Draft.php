<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Schema\Backend;

use Opis\JsonSchema\Parsers\Drafts\Draft202012;
use Opis\JsonSchema\Parsers\Keywords\MultipleOfKeywordParser;

/**
 * Draft 2020-12 — the one dialect every schema is compiled to — with the
 * backend's `multipleOf` parser replaced by the decimal one. Replaced, not
 * added beside: a keyword parsed twice is judged twice, and the backend's
 * verdict would win whenever it was the stricter of the two.
 *
 * @internal
 */
final class Draft extends Draft202012
{
    #[\Override]
    protected function getKeywordParsers(): array
    {
        $parsers = [];
        foreach (parent::getKeywordParsers() as $parser) {
            $parsers[] = $parser instanceof MultipleOfKeywordParser ? new DecimalMultipleOfKeywordParser() : $parser;
        }

        return $parsers;
    }
}
