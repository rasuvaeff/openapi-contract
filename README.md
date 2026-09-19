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
- no extension beyond `json`: `multipleOf` is judged on the decimals the
  document and the message spell (`64.1` is a multiple of `0.1`;
  `64.10000000000001` is not), exactly, whether or not `ext-bcmath` is
  loaded — the backend's own arithmetic, which read the parsed doubles and
  answered differently with and without the extension, is not used (#151)

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
Every Schema Object the validators will read — each parameter's, each
request and response media type's, each response header's and multipart
part header's — is compiled while the contract is built, in the direction
it will be read in, so a schema this package cannot evaluate (an assertion
keyword outside the support matrix such as `patternProperties`, a `$schema`
naming another dialect, an OAS 3.0 `exclusiveMinimum` written as a number,
a `pattern` the backend cannot parse, wherever it is nested) is
`InvalidContract` out of the factory and never out of a `validate*()` call.

Every exception this package raises implements `ContractException`, so a
caller can catch the package as one type: `InvalidContract` (with
`UnsupportedVersion` and `UnsupportedSerialization` under it),
`InvalidLimits`, `UnknownOperation` and `ContractViolation`. The concrete
base classes stay what they were — `\InvalidArgumentException` and
`\RuntimeException` — so existing catches keep working.

A header parameter named `Accept`, `Content-Type` or `Authorization` is
ignored, as both specifications require: HTTP gives those three a meaning of
their own, and OpenAPI describes them elsewhere — content negotiation by the
`content` map, authentication by the security schemes. Under OAS 3.0 a
`requestBody` on `GET`, `HEAD` or `DELETE` is ignored too, which is what that
dialect tells consumers to do; OAS 3.1 permits it and it is validated.

Every path-template placeholder must have an effective `in: path` parameter
with the same name and explicit `required: true`; extra path parameters are
rejected while compiling the contract.

Declarations are read strictly rather than leniently. A `requestBody`,
`parameters`, `content`, `encoding`, `headers` or Schema Object whose shape
this package cannot read is `InvalidContract` at load time, not a silently
unchecked part of the contract — and the check reaches every subschema, so an
unreadable `items` or `properties` member is refused where it is written rather
than on the first message that reads it; a boolean field written as a string
(`required: "true"`) is rejected instead of falling back to its default; a
schema carrying a value JSON cannot encode (YAML's `.nan` and `.inf`) is
rejected before it can reach the validation backend; a document whose `paths`
produce no operation at all is rejected rather than compiled into a contract
that answers `UnknownOperation` to every request; and a YAML file that does
not parse is reported as `InvalidContract`, never as the parser's own
exception type.

`$ref` siblings are read by the dialect the document declares. In 3.0 they are
ignored everywhere — the specification says a Reference Object's added
properties "SHALL be ignored", and a 3.0 Schema Object holds a Reference
Object rather than a 2020-12 schema. In 3.1 a Reference Object keeps only
`summary` and `description`, which override the referenced ones, while a
Schema Object's siblings apply *in addition* to what the reference brings, as
2020-12 requires: `{$ref: Count, maximum: 10}` asserts both `Count` and the
maximum, and compiles to the corresponding `allOf`.

`fromFile()` also resolves relative `$ref`s to sibling JSON/YAML files.
Every referenced file must stay inside the entry file's directory tree:
absolute paths, URI schemes, percent-encoded paths, traversal, and symlink
escapes are rejected before any read, and resolution errors report paths
relative to the document root. `fromArray()` and `fromJson()` have no
trusted filesystem root and accept same-document references only.
Documents are bounded: byte size, JSON depth, `$ref` depth, the number of
nodes a document expands into, a reference-resolution budget, and — for
multi-file documents — file-count, byte and node budgets shared across the
whole reference graph. The node budget is the one that bounds YAML: anchors
and aliases produce nodes out of no bytes at all, so a file well inside the
byte budget can still expand into hundreds of millions of nodes. The
resolution budget (`resolvedNodes`) bounds the work of inlining: a component
shared by many operations is visited once per use, so a large description
costs more resolution than it has nodes — GitHub's REST API, at 1 239
operations, needs about 360 000 of the default million.

#### Budgets

`Limits` carries the budgets that are the caller's to set, and every factory
takes one:

```php
use Rasuvaeff\OpenApiContract\Limits;

$contract = Contract::fromFile('openapi.yaml', new Limits(
    documentBytes: 40 * 1024 * 1024,   // default 10 MiB
    messageBodyBytes: 8 * 1024 * 1024, // default 1 MiB
    documentFiles: 256,                // default 64
    documentNodes: 20_000_000,         // default 5 000 000
    resolvedNodes: 4_000_000,          // default 1 000 000
));
```

A budget is a policy, not a verdict. A body over `messageBodyBytes` is
reported as `request.body.too_large` / `response.body.too_large`, and that
code says the validator declined to read the body — not that the message was
found wrong. A gate that rejects on `isValid()` would therefore reject traffic
it never judged, so an application whose bodies are legitimately larger raises
the budget instead of reading the violation as a failure. The defaults are
small on purpose: an unbounded read inside a middleware is a denial of
service. A budget below 1 is refused with `InvalidLimits`, an
`\InvalidArgumentException` that implements `ContractException`.

### Operations and matching

```php
foreach ($contract->operations() as $operation) {
    // Operation: key, operationId, method, path, parameters, requestBody,
    // responses, security, servers, dialect
}

$matched = $contract->match($request);        // MatchedOperation|null
$matched = $contract->requireMatch($request); // throws UnknownOperation
$operation = $contract->operation('pets.get'); // throws UnknownOperation

// The Response Object a status resolves to — exact code, then the NXX range,
// then `default` — as response validation selects it; null when the status is
// not declared, or is not an HTTP status at all.
$declared = $operation->responseFor(404); // ['key' => '4XX', 'definition' => [...]] | null
```

`Operation` identity is the `operationId` when present, otherwise the stable
`METHOD /path` fallback. `Operation` is a read model: a contract is built by
compiling a document, and the shapes the constructor takes are the compiler's
output rather than a checked input — nothing public validates a hand-built
operation. The constructor is public API all the same, because consumers
build operations by hand in their tests: it is append-only (a minor release
may add a defaulted parameter at the end, never reorder or remove one), so
construct it with named arguments. The shapes a consumer imports —
`CompiledParameter`, and `CompiledRequestBody`/`CompiledResponses` for
`$requestBody`/`$responses` — are read-only for it, and a minor release may
add keys to them. `CompiledRequestBody` and `CompiledResponses` are the
Request Body Object and the Responses Object as the compiler leaves them:
every `$ref` on the way to a schema resolved, `required` a boolean,
`content` keyed by media type, `encoding` and `headers` keyed by property and
header name, and every `schema` a boolean or a keyword map (the empty map
being the unconstrained schema); what the document wrote beside those keys
is kept as written. A schema that refers to itself — a tree whose
`children` are trees, a thread, a nested error — cannot be inlined, so the
members of every reference cycle are kept as the schema's `$defs`, named
after their JSON Pointer (`#/components/schemas/Node` becomes
`components.schemas.Node`, a member of another file `a.json:Node`), and the
reference back to one is a local `{$ref: '#/$defs/…'}` carrying the
target's `type`; the schema the cycle starts from is inlined where it is
first met and kept as a def as well. A schema without a cycle has no
`$defs`. A reference cycle outside a schema — a Path Item or a Response
that reaches itself — is refused, as is a cycle with no schema in it.
 `CompiledResponses` is keyed by status code as PHP reads
it (`"200"` is `int 200`), by the `NXX` range, or by `default`. Compiled parameters carry `allowReserved` for those consumers:
validation never reads it, because a value that leaves a reserved character
unencoded cannot be told from the delimiter it looks like — the package reads
such a query exactly as the SAPI does — while a consumer that renders a query
value cannot derive it from the schema and needs it to decide whether reserved
characters are percent-encoded. A Path Item's parameters and an Operation's are
merged by location and name, and an Operation's declaration replaces the Path
Item's for the same pair, as the specification requires; the same pair
declared twice *within* one list is rejected, because a parameter is unique by
name and location and reading either declaration would silently drop the
other. Header names compare case-insensitively, so `X-Trace` and `x-trace` are
one parameter. Compiled parameters keep declared `example`/
`examples` values as annotations: validation ignores them, while the generator
package feeds them into its deterministic example phase. An Example Object
reached through `$ref` is resolved; what an example *contains* is data and is
kept exactly as written, `$ref`-looking members included — as are a Schema
Object's `default`/`const`/`enum` and every specification extension. `MatchedOperation` carries the operation and the raw path parameters
extracted from the URI. Matching honours server base paths,
prefers concrete paths over templated ones, and splits the path on the raw
`/` before decoding each segment exactly once — so a percent-encoded
separator is part of its segment, never a boundary: `/pets/a%2Fb` matches
`/pets/{name}` with `name` decoded to `a/b`, the value the application
receives, and `/a%2Fb/x` does not match `/a/b/x`. A trailing slash is part of the path: `/pets` and `/pets/` are different
resources, as RFC 3986 has them. A
placeholder may share its segment with literals (`/report.{format}`,
`/v{version}/items`, `/{a}-{b}`); the literal runs are matched as written.

Servers are compiled as a full model (`Operation::$servers`): scheme, host,
port, and base path, with operation > path > root precedence and server
variables substituted with their declared defaults. An absolute server
constrains every URI component the request actually carries — normalized
scheme, host, and effective port (`443` for `https`, `80` for `http`) — so
the same path on two hosts selects only the right operation; a relative
server and a path-only request URI stay host-agnostic — a request that carries
no authority is matched by path alone, and is deliberately not rejected for
failing to name a host it never claimed. Undeclared variables,
missing or non-enum defaults, unsupported schemes, and userinfo/query/
fragment parts of a server URL fail closed at compile time.
When the request path is declared but no server authority agrees,
validation reports `request.server.mismatch` instead of
`request.operation.unknown`.

Parameters are deserialized where an encoding exists and read as sent where
one does not. A path segment and a query string are built out of RFC 3986
delimiters, so a value carrying one has to be escaped and RFC 6570 says how:
both are percent-decoded, and a query is form-encoded content, so `+` is a
space. A cookie is decoded too, because every SAPI decodes `$_COOKIE`; its
pairs are split on `;` (with the optional whitespace RFC 6265 allows after
it) and never on `&`, which is an ordinary cookie-octet — `sid=abc&def` is
one cookie with a seven-character value. A wire string is read as an
`integer` or `number` only when it spells one by the JSON number grammar
(`-?(0|[1-9][0-9]*)(.[0-9]+)?([eE][+-]?[0-9]+)?`, nothing before or after):
`.5`, `5.`, ` 5`, `5\n` and `0x1A` stay strings and fail the schema, and an
integer past PHP's range keeps its magnitude as a float rather than
saturating. A
**header field value is read verbatim** — HTTP treats it as opaque octets,
nothing in the wild escapes one, and decoding it would rewrite a value the
application receives intact (`X-Path: /a%20b` is a literal path; `X-Discount:
50%` is not a broken escape). The price is explicit: a header value cannot
carry its own style delimiter, because there is no escape left for it.

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
requires, fails closed as `InvalidContract` at compile time. Two things are
checked for shape only: an `oauth2` scheme whose `flows` object declares no
flow compiles to an empty `flows`, and the URL fields (`tokenUrl`,
`authorizationUrl`, `refreshUrl`, `openIdConnectUrl`) must be non-empty
strings but are not parsed as URLs.

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
only. `readOnly`/`writeOnly` properties are applied directionally — see
[Checking one schema](#checking-one-schema) for what that means. Root-level `security` is inherited by operations, an
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

A parameter name that occurs more than once, where its style admits a single
value, is a violation rather than a value. `?n=5&n=999` is a well-formed query
whose meaning depends on the runtime — PHP keeps the last occurrence, Go the
first, Node both — so reading either one would let a request satisfy the
contract with one value and hand the application another. An exploded list is
untouched: repeating the name is what that style means.

A `content` map is matched by specificity, not by the order its keys were
written in: an exact `type/subtype` wins over `type/*+suffix`, which wins over
`type/*`, which wins over `*/*`; only equally specific keys are settled by
declaration order. Declaring a wildcard above an exact media type therefore
says the same thing as declaring it below one.

A declared non-JSON media type on either side (`text/plain`, `text/csv`,
`application/octet-stream`, ...) is validated as far as its schema allows:
without a schema the body is opaque and passes; with a string-typed schema
(`type: string`, with `minLength`/`maxLength`/`pattern` and any asserted `format`) the raw
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
Bodies larger than the configured `messageBodyBytes` (1 MiB by default)
produce the corresponding `request.body.too_large` or `response.body.too_large`
violation, which says the body was not read rather than that it was wrong.
A JSON body is decoded with a nesting budget of 64 levels; one nested deeper
is reported as `request.body.json` / `response.body.json` — the decoder cannot
tell a budget overrun from malformed JSON, so the code says "not valid JSON"
where "not read" would be more precise. The budget is not configurable.
`ValidationResultFormatter` renders every violation in stable order with
bounded fields, depth, item counts, and expected/actual values. A value is
rendered only where its name can be checked: a body is redacted wholesale —
its member names are the application's and a whole-body violation has the
instance path `$` — and so is a cookie, which is a credential carrier by
definition whatever the document named it, while a parameter is rendered with any member whose name
matches the credential pattern (`authorization`, `api_key`, `token`, `secret`,
`password`, `cookie`) replaced, and a parameter whose own name matches is
redacted outright. `ContractViolation` uses the same rendering.

### Checking one schema

`Contract::accepts()` answers, for one Schema Object of this document, the
question validation asks of it: does this value satisfy it? It is the same
compiled schema and the same backend `validateRequest()` and
`validateResponse()` use, and the contract's compilation cache is reused, so
checking many values against one schema compiles it once.

```php
use Rasuvaeff\OpenApiContract\SchemaDirection;

$schema = $contract->operation('pets.create')->parameters[0]['schema'];

$contract->accepts(42, $schema);                             // request direction
$contract->accepts($value, $schema, SchemaDirection::Response);
```

The direction is not decoration. A `readOnly` property is not required on a
request and a `writeOnly` one is not required on a response: before the value
is judged, the property loses its `required` entry for the foreign direction
and keeps its subschema — it stays declared and typed, so a request that
carries a `readOnly` `id` is judged by `id`'s schema and a closed object
(`additionalProperties: false`) still admits it, exactly as both
specifications have it ("the required will take effect on the response
only"). The rewrite recurses through `properties`, `items`,
`additionalProperties`, the composition keywords and `$defs`, leaves `not` alone, and
is what makes the same value and the same schema answer differently in the two
directions.

The rewrite itself is exported, so a consumer that builds values for one
direction builds them against the schema they will be checked by rather
than against a copy of the rule:

```php
$check = new SchemaCheck();
$requestSchema = $check->effective($schema, SchemaDirection::Request);
```

`effective()` returns exactly what the validators compile — a fixed point of
itself, dialect-independent, with every member it does not read passed through
as written.

The value is judged as the backend reads JSON: an object is a `stdClass`, the
way `json_decode()` produces one without `associative: true`. An associative
PHP array is a JSON *array*, which no `type: object` schema admits. A
parameter travels as a string on the wire and is decoded before it is judged,
so pass the decoded value rather than the wire spelling.

A consumer holding an `Operation` and no contract uses `SchemaCheck`, naming
the dialect the operation carries:

```php
use Rasuvaeff\OpenApiContract\SchemaCheck;

$operation = $contract->operation('pets.create');
$check = new SchemaCheck();

$check->accepts($value, $schema, $operation->dialect);
```

`Operation::$dialect` is filled by compilation from the document's `openapi`
version — `SchemaDialect::OpenApi30` or `SchemaDialect::OpenApi31` — because
the dialect decides how a schema is read: 3.0 spells nullability as
`nullable: true` and the exclusive bounds as booleans, 3.1 as a type union and
as numbers. A schema a dialect cannot read raises `InvalidContract` rather
than being silently read as something else.

Two things a hand-written schema can meet that a document schema cannot,
because the compiler settles them at load time. A `$ref` is resolved only
inside the schema itself (`#/$defs/…`); a reference to `#/components/…`, to
another file or to a URL raises `InvalidContract`. A schema that cannot be
encoded as JSON — `NAN`/`INF`, malformed UTF-8, more than 512 levels of
nesting — raises `InvalidContract` too, as does a list where an object was
expected. The compilation cache behind both methods is keyed by the schema
and never evicts: a `Contract` holds finitely many schemas, but a
`SchemaCheck` fed an unbounded stream of distinct schemas grows with it —
keep one per document, not one per generator.

`SchemaCheck::isMultipleOf($value, $divisor)` is the `multipleOf` verdict
itself, static and exported for a consumer that has to predict it — a
generator deciding whether the `number` branch of a `oneOf` admits an integer
it is about to keep on the `integer` branch asks this instead of keeping a
second copy of the rule. It judges on the decimals the two numbers spell
(`64.1` is a multiple of `0.1`, `64.10000000000001` is not), exactly, on
every machine.

### Violation codes

The complete set. A code is a stable identifier callers may switch on; the
message text `ValidationResultFormatter` renders beside it is a diagnostic and
may be reworded in any release, so pin codes rather than text.

| Code | Raised when |
|---|---|
| `request.operation.unknown` | no operation matches the request |
| `request.server.mismatch` | the path matches, but no declared server does |
| `request.parameter.missing` | a `required` parameter is absent |
| `request.parameter.duplicate` | a name carries more than one value where its style admits one |
| `request.parameter.serialization` | a parameter value cannot be deserialized in its style |
| `request.parameter.schema` | a parameter value does not satisfy its schema |
| `request.body.missing` | a `required` body is empty |
| `request.body.media_type` | the body's media type is not declared (or the body declares no content) |
| `request.body.json` | a JSON body does not parse |
| `request.body.decode` | a form or multipart body cannot be decoded as declared |
| `request.body.schema` | the body does not satisfy its schema |
| `request.body.unsupported` | a non-JSON, non-form media type carries a schema no undecoded payload can be judged against |
| `request.body.too_large` | the body is over the configured `messageBodyBytes`, so it was not read |
| `request.body.non_seekable` | the body stream cannot be rewound, so it is not consumed |
| `request.body.unreadable` | the body stream reports more data and then reads none |
| `response.operation.unknown` | `validateResponse()` was given an operation key the contract does not have |
| `response.status.invalid` | the status is not an HTTP status code (outside 100-599) |
| `response.status.mismatch` | the status is valid but the operation declares no response for it |
| `response.header.missing` | a `required` response header is absent |
| `response.header.serialization` | a response header value cannot be deserialized |
| `response.header.schema` | a response header value does not satisfy its schema |
| `response.header.unsupported` | a Header Object uses `content` or a style other than `simple` |
| `response.body.missing` | a response that declares a schema answered with nothing |
| `response.body.media_type` | the response media type is not declared |
| `response.body.json` | a JSON response body does not parse |
| `response.body.schema` | the response body does not satisfy its schema |
| `response.body.unsupported` | as `request.body.unsupported`, on the response side |
| `response.body.too_large` | the response body is over the configured `messageBodyBytes`, so it was not read |
| `response.body.non_seekable` | the response body stream cannot be rewound |
| `response.body.unreadable` | the response body stream reports more data and then reads none |

Where the strictness stops is deliberate: this package checks what a verdict
about a message depends on, and does not check what only affects
documentation. A missing `url` on a server, a security scheme without the
fields its type requires, an operation without `responses` — all refused,
because validation cannot proceed without them. A Response Object without its
REQUIRED `description`, or an `encoding` declared on a media type the
specification does not apply it to — accepted, because neither changes a
verdict. Reach for a linter for the rest.

Three divergences from the specification are deliberate and pinned:

| Where | The specification | This package |
|---|---|---|
| A percent-encoded delimiter inside a `pipeDelimited` or `spaceDelimited` value | `\|` and space MUST be percent-encoded inside a value, so `?ids=a%7Cb` is the single element `a\|b` | folds the encoded form into the delimiter and reads two elements — which is what a PHP application reading the same query does, and agreeing with the application is the point of a validator. A value containing the delimiter cannot be expressed |
| A Header Object carrying `name` or `in` | both MUST NOT be specified | ignored, not refused: the header's name comes from the map key either way |
| `example` and `examples` on the same object | mutually exclusive | both are kept as annotations and handed to consumers; the validator reads neither |

`deepObject` is not among them: `f%5Ba%5D=1` and `f[a]=1` are the same
parameter here and in PHP's own query parsing.

Five keywords are accepted and never read, because none of them changes a
verdict this package can give: `allowEmptyValue` (its meaning is undefined
by the specification, and a parameter with an empty value is judged by its
schema), `discriminator` (a hint for consumers choosing among `oneOf`
branches; the branches themselves are still evaluated), `xml`, `externalDocs`
and `deprecated`. They are kept as written on the compiled operation.

#### Formats

`format` is asserted where the backend has a checker and is an annotation
everywhere else — a value with an unknown or unchecked format is never
rejected for it:

| Type | Asserted | Annotation only |
|---|---|---|
| `string` | `date`, `time`, `date-time`, `duration`, `uri`, `uri-reference`, `uri-template`, `regex`, `ipv4`, `ipv6`, `uuid`, `email`, `hostname`, `idn-hostname`, `idn-email`, `iri`, `iri-reference`, `json-pointer`, `relative-json-pointer` | `byte`, `binary`, `password`, and any other value |
| `integer` | `int32` (−2³¹ … 2³¹−1), `int64` (−2⁶³ … 2⁶³−1; a value that overflows PHP's integer arrives as a float and is judged by magnitude) | any other value |
| `number` | — | `float`, `double`, and any other value |

## Security

**Declared `security` is not enforced.** Requirements are compiled, and a
requirement naming an undeclared scheme fails the document — but a request
missing its API key validates clean. This package checks the shape of an
exchange against the contract, not the authorization of the caller; putting
credentials on a request belongs to `rasuvaeff/property-testing-openapi`, and
enforcing them belongs to the application's middleware.

Unsupported contract semantics are never ignored: versions, dialects,
references, serialization styles, and schema assertions outside the support
matrix fail closed, and a declared constraint this package cannot evaluate is
reported rather than skipped. What it *can* evaluate, it evaluates: a schema
form it does not recognise is handed to the backend instead of being dropped,
because silently unchecking part of a contract is the one failure a validator
must never produce. User-supplied documents and message bodies are read with
byte and JSON-depth budgets, and diagnostics render expected/actual values
in bounded form without exposing credential parameters.

A `pattern` keyword is a regular expression from the document, and the
validation backend runs it with `preg_match`. A contract is a trusted input —
it is your document, not your traffic — but if you compile documents supplied
by someone else, note that a catastrophically backtracking pattern is theirs
to choose. PHP's `pcre.backtrack_limit` bounds each match and a match that
hits the limit fails closed rather than hanging.

## Examples

Runnable scripts live in [examples/](examples/README.md).

Schema compilation is cached per `Contract`: the directional rewrite, the JSON
round trip, and the backend's own parse happen once per distinct schema,
direction and dialect rather than once per validated message. A contract
offers the same handful of schemas on every request, so this is where the cost
belongs — `composer bench` measures the difference.

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
