<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Serialization;

/**
 * A parameter name that carries more than one value where its style admits
 * exactly one.
 *
 * It is separate from the other deserialization failures because it is the
 * one that cannot be decided by reading harder: `?n=5&n=999` is a well-formed
 * query, and which of the two the application acts on is a property of the
 * runtime, not of the request. PHP keeps the last, Go keeps the first, Node
 * keeps both — so the only answer that stays true after the validator hands
 * the request on is to refuse it.
 *
 * @internal
 */
final class DuplicateParameterValue extends \InvalidArgumentException {}
