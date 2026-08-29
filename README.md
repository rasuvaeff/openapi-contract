# rasuvaeff/openapi-contract

Framework-neutral validation of PSR-7 request/response exchanges against
OpenAPI 3.0 and 3.1 contracts.

> Using an AI coding assistant? [llms.txt](llms.txt) is a compact,
> self-contained API reference for this package.

> Status: pre-release. The public contract API below is stable enough for
> dogfooding; the first tagged release follows the property-testing-openapi
> release train.

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
`UnsupportedVersion`, unknown JSON Schema dialects, non-local references,
ambiguous path templates, duplicate operation identities, and malformed
document shapes throw `InvalidContract`, and parameter `content`
serialization or unsupported styles throw `UnsupportedSerialization`.
Every path-template placeholder must have an effective `in: path` parameter
with the same name and explicit `required: true`; extra path parameters are
rejected while compiling the contract.
Documents are bounded: byte size, JSON depth, `$ref` depth, and a shared
node budget.

### Operations and matching

```php
foreach ($contract->operations() as $operation) {
    // Operation: key, operationId, method, path, parameters, requestBody,
    // responses, serverBases, security
}

$matched = $contract->match($request);        // MatchedOperation|null
$matched = $contract->requireMatch($request); // throws UnknownOperation
$operation = $contract->operation('pets.get'); // throws UnknownOperation
```

`Operation` identity is the `operationId` when present, otherwise the stable
`METHOD /path` fallback. `MatchedOperation` carries the operation and the raw
path parameters extracted from the URI. Matching honours server base paths,
prefers concrete paths over templated ones, decodes each segment exactly
once, and rejects decoded separators that would escape a template slot.

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
invented body or header violations. `readOnly`/`writeOnly` properties are
applied directionally. Root-level `security` is inherited by operations, an
explicit empty `security` list marks an operation anonymous, and credential
acquisition stays in the generator package.

`validateResponse()` validates a response fixture by operation identity without
requiring a live request. Unknown operation keys produce a single structured
`response.operation.unknown` violation.

Request bodies with `application/x-www-form-urlencoded` are decoded using the
same form parameter rules as query parameters. `multipart/form-data` bodies
support bounded part parsing, JSON and binary parts, repeated array parts, and
per-property `encoding` content types/required headers. Unsupported styles,
malformed boundaries, duplicate scalar parts, and invalid part content fail
closed as `request.body.decode`.

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
matrix fail closed. User-supplied documents and message bodies are read with
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
`league/openapi-psr7-validator`. The backend decision and executable corpus
status are recorded in [FEASIBILITY.md](FEASIBILITY.md).

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
