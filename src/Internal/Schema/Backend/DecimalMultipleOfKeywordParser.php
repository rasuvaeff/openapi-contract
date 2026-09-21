<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Schema\Backend;

use Opis\JsonSchema\Exceptions\InvalidKeywordException;
use Opis\JsonSchema\Info\SchemaInfo;
use Opis\JsonSchema\Keyword;
use Opis\JsonSchema\Parsers\KeywordParser;
use Opis\JsonSchema\Parsers\SchemaParser;

/**
 * Parses `multipleOf` the way the backend does — the same refusals of a
 * non-numeric, non-finite or non-positive divisor — and hands the divisor to
 * {@see DecimalMultipleOfKeyword} instead of the backend's keyword.
 *
 * @internal
 */
final class DecimalMultipleOfKeywordParser extends KeywordParser
{
    private const string KEYWORD = 'multipleOf';

    public function __construct()
    {
        parent::__construct(self::KEYWORD);
    }

    #[\Override]
    public function type(): string
    {
        return self::TYPE_NUMBER;
    }

    #[\Override]
    public function parse(SchemaInfo $info, SchemaParser $parser, object $shared): ?Keyword
    {
        /** @var mixed $data */
        $data = $info->data();
        if (!is_object($data) || !property_exists($data, self::KEYWORD)) {
            return null;
        }
        /** @var mixed $divisor */
        $divisor = $data->{self::KEYWORD};
        if (!is_int($divisor) && !is_float($divisor) || is_nan($divisor) || !is_finite($divisor)) {
            throw new InvalidKeywordException('multipleOf must be a valid number (integer or float)', self::KEYWORD, $info);
        }
        if ($divisor <= 0) {
            throw new InvalidKeywordException('multipleOf must be greater than zero', self::KEYWORD, $info);
        }

        return new DecimalMultipleOfKeyword($divisor);
    }
}
