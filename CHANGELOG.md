# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.3.0 — 2026-09-03

- `Contract::securitySchemes()` exposes `components.securitySchemes` as an
  immutable typed map keyed by the names `Operation::$security` refers to
  (#26): `type` plus exactly the fields the type defines (`apiKey`: `name`,
  `in`; `http`: `scheme`, optional `bearerFormat`; `oauth2`: `flows` with
  per-flow URLs and `scopes`; `openIdConnect`: `openIdConnectUrl`;
  `mutualTLS`, OpenAPI 3.1 only). Descriptions and extensions are dropped and
  `$ref`s inside a scheme are resolved. **Breaking:** a scheme without a
  supported `type`, or missing a field its type requires, now fails closed as
  `InvalidContract` at compile time — previously any named object was
  accepted and only the name was kept.
- Present response headers are validated against their Header Object
  `schema` (#27): the value is decoded with the `simple` style (`explode` as
  declared), coerced like request header parameters, and validated in the
  response direction. New codes: `response.header.schema`,
  `response.header.serialization`, and `response.header.unsupported` for a
  `content`-form Header Object or a non-`simple` style. A `Content-Type`
  header declaration is ignored as the specification requires. Previously
  only the presence of `required` headers was checked, so
  `X-RateLimit-Remaining: banana` passed a `type: integer` declaration.
- Dev dependencies follow their current lines: `rasuvaeff/property-testing-testo`
  `^0.7`, `rasuvaeff/understudy` `^0.5`, `rasuvaeff/understudy-testo` `^0.2`,
  `infection/infection` `^0.35`.

## 0.2.1 — 2026-09-03

- Validate declared non-JSON media types as far as their schema allows
  instead of reporting every such exchange as a violation (#25). Without a
  schema the body is opaque and passes; with a string-typed schema the raw
  payload is validated as that string value (`request.body.schema` /
  `response.body.schema`); any other schema fails closed under the new codes
  `request.body.unsupported` / `response.body.unsupported`. Previously a
  declared `text/plain` or `application/octet-stream` body was always
  reported as `response.body.media_type` "not supported" (or
  `request.body.decode` on the request side), making such operations
  impossible to contract-test.

## 0.2.0 — 2026-09-01

- Reject inconsistent path parameters at compile time: every `in: path`
  parameter must be `required: true` and have a `{placeholder}` in the
  effective path template, and every placeholder must have a path parameter
  after path-level/operation-level merge (`$ref`-ed parameters included).
  Previously such documents compiled and surfaced as a repeating runtime
  violation; now they throw `InvalidContract` with the spec pointer.
- Define PSR-7 body semantics: validation never consumes the caller's stream.
  Seekable bodies have their position restored in `finally` on every path;
  non-seekable bodies are refused before reading with the stable codes
  `request.body.non_seekable`/`response.body.non_seekable`; bodies beyond the
  1 MiB `Contract::MAX_MESSAGE_BODY_BYTES` budget yield
  `request.body.too_large`/`response.body.too_large`.
- Add `ValidationResultFormatter`: deterministic, bounded human-readable
  rendering of a complete `ValidationResult` (operation, code, location,
  instance path, spec pointer, truncated expected/actual). `ContractViolation`
  uses it for its message; the structured `Violation` list stays the primary
  API.
- Validate `application/x-www-form-urlencoded` request bodies: the wire body
  is parsed as ordered pairs (no `parse_str()` bracket rewriting) with the
  form-style/Encoding Object semantics of `ParameterCodec`, then the body
  schema applies to the decoded value.
- Validate `multipart/form-data` request bodies with a bounded parser:
  quoted/unquoted boundaries, per-part headers, JSON/text/binary parts,
  repeated parts for arrays, per-property `contentType`/Encoding Object;
  budgets of 128 parts and 16 KiB of headers per part on top of the shared
  body budget. The default part Content-Type follows OAS 3: `text/plain` for
  primitives, `application/octet-stream` for binary strings,
  `application/json` for objects, and for arrays the default of the item
  type. Malformed framing, a part whose content type disagrees with the
  declared/default one, and budget overflow fail closed with stable
  `request.body.*` codes.
- Add `Contract::validateResponse(string $operationKey, ResponseInterface $response)`:
  standalone response validation keyed by operation identity, using the same
  exact → `NXX` → `default` selection and the same violations as
  `validateExchange()`; an unknown key yields `response.operation.unknown`.
- Add `Operation::responseFor(int $status)`: the Response Object a concrete
  status resolves to (`key` and `definition`) through the same exact →
  `NXX` → `default` selection, or `null` when the status is not declared —
  for response generators that must not copy the selector.
- Resolve relative `$ref`s to sibling JSON/YAML files through
  `Contract::fromFile()` (`fromArray()`/`fromJson()` stay same-document only).
  The entry file's directory is the trusted root; absolute paths, URI schemes,
  protocol-relative `//`, percent-encoded forms, backslashes, traversal and
  symlink escapes are rejected before any read. Graph-wide budgets: 64 files
  and the shared 10 MiB byte budget in addition to the ref-depth/node budgets;
  each file is parsed once. Errors carry root-relative paths and the raw
  pointer, never host paths.
- Model the full server URL: `Operation::$servers` (`scheme`/`host`/`port`/
  `base`, operation > path > root precedence, server-variable defaults
  substituted at compile time). `Operation::$serverBases` remains as the v0.1
  base-path projection. Matching an absolute server now requires agreement on
  every URI component the request actually carries (normalized scheme,
  case-insensitive host, effective port with 80/443 defaults); a relative
  server or a path-only request URI stays host-agnostic. A declared path whose
  server authority disagrees reports `request.server.mismatch` (spec pointer
  `/servers`) instead of `request.operation.unknown`. Undeclared variables,
  missing/non-enum defaults, unsupported schemes, bare relative paths,
  userinfo/query/fragment in a server URL fail compilation.
- Preserve parameter-level `example`/`examples` in the compiled model
  (`$ref`s resolved, present exactly when declared, a non-map `examples` fails
  compilation). Validation ignores them; they feed the generator's
  deterministic example phase in `rasuvaeff/property-testing-openapi`. The
  compiled parameter shape is published as the `CompiledParameter` psalm type
  on `Operation`.
- Dev: differential corpus against `cebe/php-openapi` (`require-dev` only)
  for the multi-file resolver under `tests/fixtures/cebe-differential/`, with
  the two pinned divergences documented (cebe hangs on a cross-file cycle and
  inlines a 41-hop chain; this package fails closed at its depth budget).
- Internal: compilation extracted from the `Contract` constructor into
  `Internal\Compilation\DocumentCompiler`; shared `MessageReading` for both
  validators; dead matching paths removed. No public API change from these.

## 0.1.0 — 2026-08-29

- Initial release: immutable compilation of OpenAPI 3.0/3.1 documents with
  fail-closed handling of unsupported versions, dialects, references, styles,
  and document budgets.
- PSR-7 operation matching with server base paths, concrete-before-templated
  precedence, and single percent-decoding.
- Request validation (parameters per location, style/explode deserialization,
  JSON bodies) and response validation (exact/`NXX`/`default` selection,
  required headers, JSON media types, directional readOnly/writeOnly).
- Stable violation codes with JSON Pointers and bounded diagnostics.
- Differential verdict corpus pinned against league/openapi-psr7-validator.
