<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Response;

/**
 * @internal
 */
final readonly class ResponseSelector
{
    /**
     * @param array<array-key, mixed> $responses
     */
    public function select(array $responses, int $status): ?SelectedResponse
    {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException(sprintf('Invalid HTTP status %d', $status));
        }

        if (array_key_exists($status, $responses)) {
            return $this->selected((string) $status, $responses[$status]);
        }

        $range = intdiv($status, 100) . 'XX';
        if (array_key_exists($range, $responses)) {
            return $this->selected($range, $responses[$range]);
        }

        if (array_key_exists('default', $responses)) {
            return $this->selected('default', $responses['default']);
        }

        return null;
    }

    private function selected(string $key, mixed $definition): SelectedResponse
    {
        if (!is_array($definition)) {
            throw new \InvalidArgumentException(sprintf('Response "%s" must be an object', $key));
        }

        if ($key === '') {
            throw new \LogicException('Response key must not be empty');
        }

        /** @var non-empty-string $key */
        /** @var array<string, mixed> $definition */
        return new SelectedResponse($key, $definition);
    }
}
