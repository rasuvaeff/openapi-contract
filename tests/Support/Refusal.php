<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Tests\Support;

/**
 * What a call refuses with: the exception class and its exact message, so a
 * data-provider test can pin every refusal text of an entry point in one table.
 */
final readonly class Refusal
{
    /**
     * @param callable(): mixed $call
     * @return array{class-string<\Throwable>|'none', string}
     */
    public static function of(callable $call): array
    {
        try {
            $call();
        } catch (\Throwable $exception) {
            return [$exception::class, $exception->getMessage()];
        }

        return ['none', ''];
    }

    /**
     * Runs the call with every PHP diagnostic turned into an exception, so a
     * code path that only warns (an undefined key, a property read on a
     * scalar) cannot pass unnoticed.
     *
     * @template T
     * @param callable(): T $call
     * @return T
     */
    public static function quietly(callable $call): mixed
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
