<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Validation;

use Psr\Http\Message\MessageInterface;
use Rasuvaeff\OpenApiContract\Contract;

/**
 * Message-reading helpers shared by the request and response validators:
 * body access that preserves seekable stream positions, media-type
 * normalization and matching, and JSON Pointer escaping.
 *
 * @internal
 */
trait MessageReading
{
    private function bodyContents(MessageInterface $message): ?string
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
                $remaining = Contract::MAX_MESSAGE_BODY_BYTES - strlen($contents);
                $chunk = $stream->read(min(8192, $remaining + 1));
                if ($chunk === '') {
                    if ($stream->eof()) {
                        break;
                    }

                    throw new \RuntimeException('Body stream did not make progress while reading');
                }
                $contents .= $chunk;
                if (strlen($contents) > Contract::MAX_MESSAGE_BODY_BYTES) {
                    throw new MessageBodyTooLarge();
                }
            }

            return $contents;
        } finally {
            $stream->seek($position);
        }
    }

    /** @param array<array-key, mixed> $content
     * @return array<array-key, mixed>|null
     */
    private function mediaDefinition(array $content, string $mediaType): ?array
    {
        foreach ($content as $declared => $definition) {
            if (!is_string($declared) || !is_array($definition)) {
                continue;
            }
            if ($this->mediaMatches($declared, $mediaType)) {
                return $definition;
            }
        }

        return null;
    }

    private function isJsonMediaType(string $mediaType): bool
    {
        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }

    private function mediaMatches(string $declared, string $actual): bool
    {
        $declared = strtolower(trim(explode(';', $declared, 2)[0]));
        if ($declared === $actual || $declared === '*/*') {
            return true;
        }
        [$declaredType, $declaredSubtype] = array_pad(explode('/', $declared, 2), 2, '');
        [$actualType, $actualSubtype] = array_pad(explode('/', $actual, 2), 2, '');
        if ($declaredType !== $actualType) {
            return false;
        }

        return $declaredSubtype === '*' || ($declaredSubtype === '*+json' && str_ends_with($actualSubtype, '+json'));
    }

    private function mediaTypeOf(MessageInterface $message): string
    {
        return strtolower(trim(explode(';', $message->getHeaderLine('Content-Type'), 2)[0]));
    }

    private function escape(string $value): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $value);
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

    /**
     * Whether a raw non-JSON payload can be validated as the string value the
     * schema describes: `type: string`, or a type list of `string` (and
     * `null`) only.
     *
     * @param array<string, mixed> $schema
     */
    private function isStringSchema(array $schema): bool
    {
        $type = $schema['type'] ?? null;
        if ($type === 'string') {
            return true;
        }
        if (!is_array($type) || !array_is_list($type) || !in_array('string', $type, strict: true)) {
            return false;
        }
        foreach ($type as $candidate) {
            if ($candidate !== 'string' && $candidate !== 'null') {
                return false;
            }
        }

        return true;
    }
}
