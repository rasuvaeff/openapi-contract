# rasuvaeff/openapi-contract

Framework-neutral validation of PSR-7 request/response exchanges against
OpenAPI contracts.

> Status: implementation in progress. The initial public contract API is
> available for pre-release use; response validation and generators are still
> being expanded.

## Scope

The package loads OpenAPI 3.0 and 3.1 documents, matches PSR-7 requests to
operations, and validates both sides of an exchange. Unsupported versions,
dialects, references, and serialization styles fail closed.

OpenAPI 3.2, remote and file references, XML, multipart, form-urlencoded, and
binary bodies are outside the initial release.

## Development

The current slice provides `Contract::fromArray()`, `fromJson()`, `fromFile()`,
operation matching, request validation, response selection, and JSON body
validation. Install and run the gate in Docker:

```bash
make install
make build
```

Tests use property-based checks for laws and serialization round-trips.

See [examples/README.md](examples/README.md) for the current example status.
The current backend decision and executable corpus status are recorded in
[FEASIBILITY.md](FEASIBILITY.md).

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
