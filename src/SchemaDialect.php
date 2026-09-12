<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

/**
 * The JSON Schema dialect an OpenAPI document's Schema Objects are written
 * in, which decides how a schema is read: OAS 3.0 spells nullability as
 * `nullable: true` and the exclusive bounds as booleans, OAS 3.1 as a type
 * union and as numbers.
 *
 * A document's dialect follows from its `openapi` version and travels with
 * every {@see Operation} the contract compiles, so a consumer holding an
 * operation can check a value against one of its schemas the same way the
 * contract does.
 *
 * @api
 */
enum SchemaDialect
{
    case OpenApi30;
    case OpenApi31;
}
