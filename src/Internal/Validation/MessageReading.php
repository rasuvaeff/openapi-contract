<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Validation;

use Psr\Http\Message\MessageInterface;
use Rasuvaeff\OpenApiContract\Internal\Schema\SchemaFailure;
use Rasuvaeff\OpenApiContract\MatchedOperation;
use Rasuvaeff\OpenApiContract\Violation;

/**
 * Message-reading helpers shared by the request and response validators:
 * body access that preserves seekable stream positions, the schema a Media
 * Type Object declares, JSON Pointer escaping, the pointer an operation
 * lives at, and the body violations a schema's leaf failures become. Media type normalization
 * and matching itself lives in {@see MediaType}, which the body decoders
 * share too.
 *
 * @internal
 */
trait MessageReading
{
    private function bodyContents(MessageInterface $message, int $maxBytes): ?string
    {
        $stream = $message->getBody();
        if (!$stream->isSeekable()) {
            return null;
        }
        $position = $stream->tell();

        try {
            $stream->rewind();
            $contents = '';
            while (!$stream->eof()) {
                $remaining = $maxBytes - strlen($contents);
                $chunk = $stream->read(min(8192, $remaining + 1));
                if ($chunk === '') {
                    if ($stream->eof()) {
                        break;
                    }

                    throw new MessageBodyUnreadable();
                }
                $contents .= $chunk;
                if (strlen($contents) > $maxBytes) {
                    throw new MessageBodyTooLarge();
                }
            }

            return $contents;
        } finally {
            $stream->seek($position);
        }
    }

    /**
     * @param array<array-key, mixed> $content
     * @return array<array-key, mixed>|null
     */
    private function mediaDefinition(array $content, string $mediaType): ?array
    {
        $selected = null;
        $rank = -1;
        foreach ($content as $declared => $definition) {
            if (!is_string($declared) || !is_array($definition)) {
                continue;
            }
            $specificity = MediaType::specificity($declared, $mediaType);
            // The most specific declaration wins, and only a tie is settled by
            // the order the keys happen to be written in.
            if ($specificity !== null && $specificity > $rank) {
                $selected = $definition;
                $rank = $specificity;
            }
        }

        return $selected;
    }

    private function mediaTypeOf(MessageInterface $message): string
    {
        return MediaType::normalize($message->getHeaderLine('Content-Type'));
    }

    private function escape(string $value): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $value);
    }

    /**
     * The JSON Pointer of the Operation Object: under `/paths` by its path,
     * under `/webhooks` by its name.
     */
    private function operationPointer(MatchedOperation $matched): string
    {
        $operation = $matched->operation;

        return sprintf(
            '%s/%s/%s',
            $operation->webhook === null ? '/paths' : '/webhooks',
            $this->escape($operation->webhook ?? $operation->path),
            strtolower($operation->method),
        );
    }

    /**
     * One violation per leaf failure of a body against its schema, in the
     * backend's order. Each names the failing member by its path and the
     * keyword it failed; a failure of the value itself — a wrong `type` at
     * the root, a `oneOf` no branch or two branches of accept — keeps the
     * path `$`, and the formatter's wholesale redaction with it.
     *
     * @param 'Request'|'Response' $side
     * @param array<string, mixed> $schema
     * @param list<SchemaFailure> $failures
     * @return list<Violation>
     */
    private function bodySchemaViolations(
        string $side,
        MatchedOperation $matched,
        string $schemaPointer,
        array $schema,
        array $failures,
    ): array {
        $violations = [];
        foreach ($failures as $failure) {
            $instancePath = $this->jsonPath($failure->path);
            $violations[] = new Violation(
                code: strtolower($side) . '.body.schema',
                operation: $matched->operation->key,
                location: 'body',
                instancePath: $instancePath,
                specPointer: $schemaPointer,
                expected: $schema,
                actual: $failure->actual,
                message: match (true) {
                    $failure->keyword === 'discriminator' => sprintf('%s body member "%s" is the discriminator, and its value names no branch', $side, $instancePath),
                    $failure->keyword === 'required' => sprintf('%s body member "%s" is required and absent', $side, $instancePath),
                    $instancePath === '$' => sprintf('%s body does not satisfy "%s"', $side, $failure->keyword),
                    default => sprintf('%s body member "%s" does not satisfy "%s"', $side, $instancePath, $failure->keyword),
                },
                keyword: $failure->keyword,
            );
        }

        return $violations;
    }

    /**
     * A member path as the instance path spells it: `$`, then `.name` for a
     * member whose name is an identifier, `['name']` for any other, and
     * `[0]` for an index — the same notation the parameter violations use.
     *
     * @param list<string|int> $path
     */
    private function jsonPath(array $path): string
    {
        $rendered = '$';
        foreach ($path as $part) {
            if (is_int($part)) {
                $rendered .= '[' . $part . ']';
            } elseif (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\z/', $part) === 1) {
                $rendered .= '.' . $part;
            } else {
                $rendered .= "['" . str_replace(['\\', "'"], ['\\\\', "\\'"], $part) . "']";
            }
        }

        return $rendered;
    }

    /**
     * Whether a Media Type Object declares the boolean schema `false`, which
     * admits no value at all. `true` is the unconstrained boolean schema and
     * is indistinguishable from an absent declaration, so only `false` needs
     * telling apart from "no schema here".
     *
     * @param array<array-key, mixed> $definition
     */
    private function declaresNothingValid(array $definition): bool
    {
        return ($definition['schema'] ?? null) === false;
    }

    /**
     * The Schema Object a Media Type Object declares, or `null` when it
     * declares none.
     *
     * @param array<array-key, mixed> $definition
     * @return array<string, mixed>|null
     */
    private function declaredSchema(array $definition): ?array
    {
        $schema = $definition['schema'] ?? null;
        if (!is_array($schema) || array_is_list($schema)) {
            return null;
        }
        foreach (array_keys($schema) as $key) {
            if (!is_string($key)) {
                return null;
            }
        }

        /** @var array<string, mixed> $schema */
        return $schema;
    }

}
