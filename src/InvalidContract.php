<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

/**
 * A document this package cannot accept: a shape it cannot read, a version,
 * dialect, reference or serialization it does not support.
 *
 * This is deliberately the base of {@see UnsupportedVersion},
 * {@see UnsupportedSerialization} and the internal `Unsupported*` errors, so
 * that catching it catches every way a contract can be wrong. It is not an
 * extension point for callers — the package's own subtypes are the only ones
 * it is meant to have.
 *
 * @api
 */
class InvalidContract extends \InvalidArgumentException implements ContractException {}
