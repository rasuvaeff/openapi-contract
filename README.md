# rasuvaeff/openapi-contract

Framework-neutral validation of PSR-7 request/response exchanges against
OpenAPI contracts.

> Status: feasibility work for the 0.1 contract is in progress. There is no
> stable public API yet and the package must not be released in this state.

## Scope

The package will load OpenAPI 3.0 and 3.1 documents, match PSR-7 requests to
operations, and validate both sides of an exchange. Unsupported versions,
dialects, references, and serialization styles fail closed.

OpenAPI 3.2, remote and file references, XML, multipart, form-urlencoded, and
binary bodies are outside the initial release.

## Development

The current milestone evaluates JSON Schema backends with an executable OAS
3.0/3.1 corpus and establishes response selection semantics. Install and run
the gate in Docker:

```bash
make install
make build
```

Tests use property-based checks for laws and serialization round-trips, and
Understudy Testo doubles for observable PSR boundary interactions.

See [examples/README.md](examples/README.md) for the current example status.
The current backend decision and executable corpus status are recorded in
[FEASIBILITY.md](FEASIBILITY.md).

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
