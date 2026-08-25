<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Serialization;

/**
 * @internal
 */
enum ParameterStyle
{
    case Simple;
    case Label;
    case Matrix;
    case Form;
    case SpaceDelimited;
    case PipeDelimited;
    case DeepObject;
}
