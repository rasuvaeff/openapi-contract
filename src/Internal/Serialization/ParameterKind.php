<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Serialization;

/**
 * @internal
 */
enum ParameterKind
{
    case Scalar;
    case List;
    case Object;
}
