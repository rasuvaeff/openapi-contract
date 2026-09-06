<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Validation;

/**
 * A body stream that says it is not at the end and then reads nothing. Like a
 * non-seekable stream and one over the byte budget, it is a fact about the
 * message, and each validator turns it into a violation of its own.
 *
 * @internal
 */
final class MessageBodyUnreadable extends \RuntimeException {}
