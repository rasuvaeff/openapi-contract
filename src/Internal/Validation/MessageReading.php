<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Validation;

use Psr\Http\Message\MessageInterface;
use Rasuvaeff\OpenApiContract\Contract;

/**
 * Message-reading helpers shared by the request and response validators:
 * body access that preserves seekable stream positions, the schema a Media
 * Type Object declares, and JSON Pointer escaping. Media type normalization
 * and matching itself lives in {@see MediaType}, which the body decoders
 * share too.
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
