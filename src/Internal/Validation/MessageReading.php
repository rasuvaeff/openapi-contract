<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Validation;

use Psr\Http\Message\MessageInterface;

/**
 * Message-reading helpers shared by the request and response validators:
 * body access that preserves seekable stream positions, media-type
 * normalization and matching, and JSON Pointer escaping.
 *
 * @internal
 */
trait MessageReading
{
    private function bodyContents(MessageInterface $message): string
    {
        $stream = $message->getBody();
        if (!$stream->isSeekable()) {
            return $stream->getContents();
        }
        $position = $stream->tell();
        $stream->rewind();
        $contents = $stream->getContents();
        $stream->seek($position);

        return $contents;
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
}
