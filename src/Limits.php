<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

/**
 * The resource budgets a contract refuses to exceed.
 *
 * A budget is a policy, not a verdict. A body over `messageBodyBytes` is
 * reported as `request.body.too_large`/`response.body.too_large`, which says
 * the validator declined to read that body — not that the message is wrong.
 * The defaults are small on purpose, because an unbounded read inside a
 * middleware is a denial of service; a caller whose traffic is legitimately
 * larger raises the budget here instead of losing the verdict.
 *
 * @api
 */
final readonly class Limits
{
    public const int DEFAULT_DOCUMENT_BYTES = 10 * 1024 * 1024;
    public const int DEFAULT_MESSAGE_BODY_BYTES = 1024 * 1024;
    public const int DEFAULT_DOCUMENT_FILES = 64;

    public function __construct(
        public int $documentBytes = self::DEFAULT_DOCUMENT_BYTES,
        public int $messageBodyBytes = self::DEFAULT_MESSAGE_BODY_BYTES,
        public int $documentFiles = self::DEFAULT_DOCUMENT_FILES,
    ) {
        if ($documentBytes < 1) {
            throw new \InvalidArgumentException('Document byte budget must be positive');
        }
        if ($messageBodyBytes < 1) {
            throw new \InvalidArgumentException('Message body byte budget must be positive');
        }
        if ($documentFiles < 1) {
            throw new \InvalidArgumentException('Document file budget must be positive');
        }
    }
}
