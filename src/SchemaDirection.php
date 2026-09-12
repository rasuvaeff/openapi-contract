<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

/**
 * Which half of an exchange a schema constrains.
 *
 * A Schema Object asserts different things about a request and about a
 * response: a `readOnly` property is not part of a request and a `writeOnly`
 * one is not part of a response, and each is dropped — with its `required`
 * entry — before the value is judged. A value checked in the wrong direction
 * is therefore judged against a schema the document never applies to it.
 *
 * @api
 */
enum SchemaDirection
{
    case Request;
    case Response;

    /** The `readOnly`/`writeOnly` flag marking a property the other direction owns. */
    public function foreignFlag(): string
    {
        return match ($this) {
            self::Request => 'readOnly',
            self::Response => 'writeOnly',
        };
    }
}
