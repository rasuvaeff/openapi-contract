<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

/**
 * A budget that admits nothing: a {@see Limits} value below 1.
 *
 * Not an {@see InvalidContract} — no document was read — but a
 * {@see ContractException} like every other exception this package raises,
 * so a caller catching the package as one type sees it. The concrete base
 * stays `\InvalidArgumentException`, which is what it was thrown as before
 * it had a name.
 *
 * @api
 */
final class InvalidLimits extends \InvalidArgumentException implements ContractException {}
