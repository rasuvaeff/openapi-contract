<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Schema;

use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator as OpisValidator;

/**
 * @internal
 */
final readonly class SchemaValidator
{
    private OpisValidator $validator;

    public function __construct(
        private SchemaCompiler $compiler = new SchemaCompiler(),
    ) {
        $parser = new SchemaParser(options: [
            'allowDataKeyword' => false,
            'allowDefaults' => false,
            'allowFilters' => false,
            'allowGlobals' => false,
            'allowKeywordValidators' => false,
            'allowMappers' => false,
            'allowPragmas' => false,
            'allowSlots' => false,
            'allowTemplates' => false,
        ]);
        $loader = new SchemaLoader(
            parser: $parser,
            decodeJsonString: true,
        );
        $this->validator = new OpisValidator(
            loader: $loader,
            max_errors: 20,
            stop_at_first_error: false,
        );
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function isValid(mixed $value, array $schema, SchemaDialect $dialect): bool
    {
        return $this->validator->validate($value, $this->compiler->compile($schema, $dialect))->isValid();
    }
}
