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
$result = $contract->validateRequest($request);
$result = $contract->validateExchange($request, $response);

$result->assertValid(); // throws ContractViolation when violations exist

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

## Security

Unsupported contract semantics are never ignored: versions, dialects,
references, serialization styles, and schema assertions outside the support
matrix fail closed. User-supplied documents and message bodies are read with
byte and depth budgets, and diagnostics render expected/actual values in
bounded form.

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
