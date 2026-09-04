# rasuvaeff/openapi-contract

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/openapi-contract/v)](https://packagist.org/packages/rasuvaeff/openapi-contract)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/openapi-contract/downloads)](https://packagist.org/packages/rasuvaeff/openapi-contract)
[![Build](https://github.com/rasuvaeff/openapi-contract/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/openapi-contract/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/openapi-contract/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/openapi-contract/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/openapi-contract/actions/workflows/static-analysis.yml)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

[Русская версия](README.ru.md)

Framework-neutral validation of PSR-7 request/response exchanges against
OpenAPI 3.0 and 3.1 contracts.

> Using an AI coding assistant? [llms.txt](llms.txt) is a compact,
> self-contained API reference for this package.

## Requirements

- PHP 8.3 – 8.5
- `psr/http-message` implementations for the exchanges you validate
- `symfony/yaml` only when loading YAML documents (suggested, not required)

## Installation

```bash
composer require rasuvaeff/openapi-contract
```

## Usage

### Loading a contract

`Contract` is the immutable compiled document:

```php
use Rasuvaeff\OpenApiContract\Contract;

$contract = Contract::fromArray($document);
$contract = Contract::fromJson($json, source: 'openapi.json');
$contract = Contract::fromFile('openapi.yaml'); // needs symfony/yaml
```

Loading fails closed: unsupported OpenAPI versions throw
`UnsupportedVersion`, unknown JSON Schema dialects, remote references,
ambiguous path templates, duplicate operation identities, and malformed
document shapes throw `InvalidContract`, and parameter `content`
serialization or unsupported styles throw `UnsupportedSerialization`.
Every path-template placeholder must have an effective `in: path` parameter
with the same name and explicit `required: true`; extra path parameters are
rejected while compiling the contract.

`fromFile()` also resolves relative `$ref`s to sibling JSON/YAML files.
Every referenced file must stay inside the entry file's directory tree:
absolute paths, URI schemes, percent-encoded paths, traversal, and symlink
escapes are rejected before any read, and resolution errors report paths
relative to the document root. `fromArray()` and `fromJson()` have no
trusted filesystem root and accept same-document references only.
Documents are bounded: byte size, JSON depth, `$ref` depth, a shared node
budget, and — for multi-file documents — file-count and byte budgets shared
across the whole reference graph.

### Operations and matching

```php
foreach ($contract->operations() as $operation) {
    // Operation: key, operationId, method, path, parameters, requestBody,
    // responses, serverBases, security, servers
}

$matched = $contract->match($request);        // MatchedOperation|null
$matched = $contract->requireMatch($request); // throws UnknownOperation
$operation = $contract->operation('pets.get'); // throws UnknownOperation
```

`Operation` identity is the `operationId` when present, otherwise the stable
`METHOD /path` fallback. Compiled parameters keep declared `example`/
`examples` values (with `$ref`s resolved) as annotations: validation ignores
them, while the generator package feeds them into its deterministic example
phase. `MatchedOperation` carries the operation and the raw path parameters
extracted from the URI. Matching honours server base paths,
prefers concrete paths over templated ones, decodes each segment exactly
once, and rejects decoded separators that would escape a template slot. A
placeholder may share its segment with literals (`/report.{format}`,
`/v{version}/items`, `/{a}-{b}`); the literal runs are matched as written.

Servers are compiled as a full model (`Operation::$servers`): scheme, host,
port, and base path, with operation > path > root precedence and server
variables substituted with their declared defaults. An absolute server
constrains every URI component the request actually carries — normalized
scheme, host, and effective port (`443` for `https`, `80` for `http`) — so
the same path on two hosts selects only the right operation; a relative
server and a path-only request URI stay host-agnostic. Undeclared variables,
missing or non-enum defaults, unsupported schemes, and userinfo/query/
fragment parts of a server URL fail closed at compile time.
`Operation::$serverBases` remains the v0.1 base-path projection of the same
list. When the request path is declared but no server authority agrees,
validation reports `request.server.mismatch` instead of
`request.operation.unknown`.

### Security schemes

```php
foreach ($contract->securitySchemes() as $name => $scheme) {
    // $scheme['type']: apiKey | http | mutualTLS | oauth2 | openIdConnect
    // apiKey: name, in — http: scheme, bearerFormat? — oauth2: flows —
    // openIdConnect: openIdConnectUrl
}
```

`components.securitySchemes` is compiled into an immutable typed map keyed by
the names that `Operation::$security` requirements refer to, so a consumer
never re-reads the raw document to learn that `apiKey` lives in the
`X-Api-Key` header. Each scheme carries `type` plus exactly the fields its
type defines: `apiKey` — `name`, `in` (`query`/`header`/`cookie`); `http` —
`scheme`, optional `bearerFormat`; `oauth2` — `flows` with the declared
`implicit`/`password`/`clientCredentials`/`authorizationCode` flows, each
with its URLs and `scopes`; `openIdConnect` — `openIdConnectUrl`;
`mutualTLS` (OpenAPI 3.1 only) — nothing else. Descriptions and extensions
are dropped. A scheme without a supported `type`, or missing a field its type
requires, fails closed as `InvalidContract` at compile time.

### Validating exchanges

```php
use Rasuvaeff\OpenApiContract\ValidationResultFormatter;

$result = $contract->validateRequest($request);
$result = $contract->validateExchange($request, $response);
$result = $contract->validateResponse('pets.get', $response);

$result->assertValid(); // throws ContractViolation when violations exist
$diagnostics = (new ValidationResultFormatter())->format($result);

foreach ($result->violations as $violation) {
    // Violation: code, operation, location, instancePath, specPointer,
    // expected, actual, message
}
```

`ValidationResult` is an immutable list of `Violation` values with stable
codes (`request.parameter.missing`, `response.body.schema`, ...) and JSON
Pointers into the OpenAPI document. Response selection follows exact status,
then the `NXX` range, then `default`; an unknown status never cascades into
invented body or header violations. A declared response header is checked
for presence when `required`, and a present header with a `schema` is decoded
with the `simple` style (`explode` as declared, optional whitespace around
the commas of a multi-valued array or object header dropped) and validated in
the response direction (`response.header.schema`,
`response.header.serialization`); a
`content`-form Header Object or a non-`simple` style fails closed as
`response.header.unsupported`, a `Content-Type` header declaration is ignored
as the specification requires, and a schema-less declaration asserts presence
only. `readOnly`/`writeOnly` properties are applied directionally. Root-level `security` is inherited by operations, an
explicit empty `security` list marks an operation anonymous, and credential
acquisition stays in the generator package.

`validateResponse()` validates a response fixture by operation identity without
requiring a live request. Unknown operation keys produce a single structured
`response.operation.unknown` violation.

Request bodies with `application/x-www-form-urlencoded` are decoded using the
same form parameter rules as query parameters, and a property that declares an
`encoding` content type carries a whole document instead: a JSON media type is
decoded and validated against the property schema, any other is validated as
the string it already is. `multipart/form-data` bodies support bounded part
parsing, JSON and binary parts, repeated array parts, and per-property
`encoding` content types and headers — a declared part header must be present
when `required` and must satisfy its schema, read with the `simple` style like
a request header parameter. Without an `encoding` content type a part defaults
to `text/plain` for primitives, `application/octet-stream` for binary strings,
`application/json` for objects, and for arrays to the default of the item type.
Unsupported styles, malformed boundaries, duplicate scalar parts, and invalid
part content fail closed as `request.body.decode`.

A declared non-JSON media type on either side (`text/plain`, `text/csv`,
`application/octet-stream`, ...) is validated as far as its schema allows:
without a schema the body is opaque and passes; with a string-typed schema
(`type: string`, any `format`, `minLength`/`maxLength`/`pattern`) the raw
payload is validated as that string value (`request.body.schema` /
`response.body.schema`); any other schema (an XML object, for example) cannot
be evaluated against an undecoded payload and fails closed as
`request.body.unsupported` / `response.body.unsupported`. An undeclared media
type stays `request.body.media_type` / `response.body.media_type`.

A response that declares a schema and arrives with an empty body produces
`response.body.missing`, the mirror of `request.body.missing`. The statuses
that carry no body by definition are excluded: `204`, `304`, and every response
to a `HEAD` request, as is a media type entry that declares no schema or the
unconstrained boolean one.

Body validation reads seekable PSR-7 streams from the beginning and restores
their original position, including when reading fails. A body that needs
validation but is non-seekable is not consumed: it produces
`request.body.non_seekable` or `response.body.non_seekable` instead.
Bodies larger than `Contract::MAX_MESSAGE_BODY_BYTES` (1 MiB) produce the
corresponding `request.body.too_large` or `response.body.too_large` violation.
`ValidationResultFormatter` renders every violation in stable order with
bounded fields, depth, item counts, and expected/actual values. It redacts
header, cookie, query, and recognizably sensitive actual values;
`ContractViolation` uses the same complete rendering.

## Security

Unsupported contract semantics are never ignored: versions, dialects,
references, serialization styles, and schema assertions outside the support
matrix fail closed, and a declared constraint this package cannot evaluate is
reported rather than skipped. What it *can* evaluate, it evaluates: a schema
form it does not recognise is handed to the backend instead of being dropped,
because silently unchecking part of a contract is the one failure a validator
must never produce. User-supplied documents and message bodies are read with
byte and JSON-depth budgets, and diagnostics render expected/actual values
in bounded form without exposing credential parameters.

## Examples

Runnable scripts live in [examples/](examples/README.md).

## Development

```bash
make install
make build
make release-check
```

Tests use property-based checks for laws and serialization round-trips, and a
differential corpus pins verdict agreement with
`league/openapi-psr7-validator`. A second committed corpus resolves the same
multi-file document trees through `cebe/php-openapi` (dev-only OAS 3.0 oracle)
and pins the deliberate divergences: our depth budget rejects deep chains the
oracle inlines, and the cross-file cycle that hangs the oracle is a fast,
stable error here. The backend decision and executable corpus status are
recorded in [FEASIBILITY.md](FEASIBILITY.md).

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
