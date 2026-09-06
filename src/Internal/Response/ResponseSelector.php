<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Response;

use Rasuvaeff\OpenApiContract\InvalidContract;

/**
 * @internal
 */
final readonly class ResponseSelector
{
    /**
     * The Response Object a status resolves to, or `null` when the responses
     * declare none for it.
     *
     * A status that is not an HTTP status at all resolves to nothing rather
     * than raising: the two callers want different answers to it — a public
     * `responseFor()` says "not declared", and response validation says the
     * *response* is wrong, which is a violation — and neither is served by an
     * exception raised here.
     *
     * @param array<array-key, mixed> $responses
     */
    public function select(array $responses, int $status): ?SelectedResponse
    {
        if ($status < 100 || $status > 599) {
            return null;
        }

        if (array_key_exists($status, $responses)) {
            return $this->selected((string) $status, $responses[$status]);
        }

        // `2XX` is the canonical spelling, but a document writing `2xx` names
        // the same range; the document's own key is kept so the specPointer
        // still points at what the file says.
        $range = intdiv($status, 100) . 'XX';
        foreach (array_keys($responses) as $key) {
            if (is_string($key) && strcasecmp($key, $range) === 0) {
                return $this->selected($key, $responses[$key]);
            }
        }

        if (array_key_exists('default', $responses)) {
            return $this->selected('default', $responses['default']);
        }

        return null;
    }

    private function selected(string $key, mixed $definition): SelectedResponse
    {
        if (!is_array($definition)) {
            throw new InvalidContract(sprintf('Response "%s" must be an object', $key));
        }

        if ($key === '') {
            throw new \LogicException('Response key must not be empty');
        }

        /** @var non-empty-string $key */
        /** @var array<string, mixed> $definition */
        return new SelectedResponse($key, $definition);
    }
}
