<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

/**
 * The type every exception this package raises implements.
 *
 * Without it, "handle anything this contract can throw" had to be spelled as
 * the current list of classes — `InvalidContract | UnknownOperation |
 * ContractViolation` — and rechecked on every upgrade, or widened to
 * `\InvalidArgumentException`, which catches this package together with
 * everything else on the stack.
 *
 * @api
 */
interface ContractException extends \Throwable {}
