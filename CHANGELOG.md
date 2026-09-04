# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- **Fixed.** OAS 3.0 documents that use a boolean `additionalProperties` no
  longer fail validation (#49). `additionalProperties` was grouped with `items`
  and `not`, which really do forbid a boolean before OAS 3.1, but the 3.0
  specification spells this one out as "Value can be boolean or object" — and
  `additionalProperties: false` is the standard closed-object idiom of the 3.0
  corpus. The document loaded and then every `validateRequest()` /
  `validateResponse()` threw `UnsupportedSchema`.
- **Fixed.** A property whose name is numeric or whose schema is a boolean is
  validated instead of being silently dropped (#50). PHP normalizes the array
  key `"2020"` to an integer, and the directional filter discarded any member
  it did not recognise — together with its `required` entry — so the whole
  declaration went unchecked in both directions, with no diagnostic. Direction
  (`readOnly`/`writeOnly`) is now the only reason a property is dropped;
  anything else is passed through for the compiler and the backend to judge,
  and the compiler no longer rejects an integer-normalized name in `properties`
  or `$defs`.
- **Fixed.** A boolean property schema in a form or multipart body no longer
  escapes as a bare `InvalidArgumentException` out of `validateRequest()`
  (#51). The body decoders read `properties` through a helper that accepted
  object schemas only; a boolean member now maps to the unconstrained schema
  for decoding, and the backend enforces what the boolean says.
- **Internal.** `MediaType::isJson()` normalizes its own argument, like the
  twin helper in `property-testing-openapi`. It assumed a pre-normalized value,
  so the answer depended on which caller had remembered to normalize first —
  the same asymmetry that hid a multipart bug in `mediaMatches()` until 0.5.1.
- **Fixed.** A `+` in the query string is read as a space (#52). A query is
  `application/x-www-form-urlencoded` content, so `+` spells a space — which is
  what an HTML form submits over GET and what every SAPI hands the application.
  Percent-decoding it literally reported a violation for the exact value the
  application receives as correct, and disagreed with this package's own form
  body decoder, which has always folded `+` first.
- **Fixed.** A query or cookie key with no `=` carries the empty value instead
  of failing deserialization (#53). An exploded object parameter is handed
  every pair a sibling parameter does not claim, so one stray `&flag` in the
  request failed an unrelated parameter. The styles that have no valueless
  form — `simple`, `label`, `matrix` — stay strict.
- **Fixed.** A multipart body whose closing delimiter carries no trailing CRLF,
  or is followed by an epilogue, is accepted (#54). RFC 2046 §5.1.1 makes both
  legal and `league/openapi-psr7-validator` accepts them; requiring the exact
  bytes `--<boundary>--\r\n` turned a conforming client into a
  `request.body.decode` violation. A body with no parts at all stays rejected —
  the same clause requires at least one — and `tests/Differential/` now pins
  every one of these four verdicts against league.

## 0.5.1 — 2026-09-04

- Media type normalization and matching moved to a shared `MediaType` helper.
  The validators and the multipart decoder each carried their own copy of the
  wildcard rules, so `*/*`, `type/*` and `*+json` were decided in two places
  that could drift; the multipart copy also skipped the normalization the
  others did, and now folds parameters and case off a part's declared type as
  well.
- The `properties` reader the form and multipart decoders duplicated verbatim
  is one method on `SchemaValueDecoder`.
- Whether an undecodable body can be judged against its schema is one
  fail-closed decision, `OpaqueBodyVerdict`, that the request and response
  validators map to their own wording. The two directions carried separate
  copies of the rule.
- `RequestValidator` computes the constraining schema once per body instead of
  twice.
- Filtering every property of an object for a direction leaves the document's
  own openness intact — `additionalProperties: false` still closes it, and its
  absence still leaves it open. Behavior is unchanged; it is now pinned by a
  test, because the open half reads like a hole in a fail-closed package and
  is not one.

## 0.5.0 — 2026-09-04

- Reference-identity keywords fail closed (#39). `$id`, `$anchor`, `$dynamicRef`
  and `$dynamicAnchor` were neither resolved nor rejected — they reached the
  validation backend as they were. This was not a missing check: a document
  using `$dynamicRef` sent the backend into unbounded recursion until it
  exhausted memory and killed the process. The backend call is also wrapped, so
  a document the compiler accepts but the backend cannot evaluate leaves as
  `UnsupportedSchema` rather than as a backend class. Dead duplicate entries for
  `dependentSchemas` and `patternProperties` were removed from the schema-map
  list; the assertion list already rejected both.
- A boolean schema is a schema (#40). `schema: false` admits nothing and
  `schema: true` constrains nothing; both were read as "no schema declared", so
  a `false` schema silently accepted every body, and on the request side `true`
  raised out of `validateRequest()`.
- Diagnostics no longer render body content (#41). Redaction was decided from
  the parameter location and the instance path, and a whole-body violation has
  neither — up to 512 bytes of the decoded payload, credentials included, went
  into the rendered message. Body violations are now redacted on the location
  alone, in both directions.
- A malformed `parameters` entry is rejected instead of skipped (#42). A
  non-object entry compiled silently and the parameter it meant to declare was
  never validated.
- `label`, `matrix` and `simple` header parameters lose the whitespace around
  their list separator (#35). PSR-7 joins repeated header lines with `", "`, so
  `X-Tags: a, b` produced the element `" b"`. The response direction already
  did this; the two now share one helper.
- An exploded object parameter whose schema leaves `additionalProperties` open
  no longer swallows the other declared parameters (#36). Which undeclared pair
  belongs to an open object is genuinely unknowable and still counts toward it —
  that is the style's ambiguity — but a pair another parameter declares is not
  part of it.
- `SchemaValueDecoder::kind()` reads an OAS 3.1 type union (#37), so
  `["array", "null"]` is parsed as a list instead of a scalar. A union naming
  both `array` and `object` cannot be told apart on the wire; the list shape
  wins, deliberately.
- `encoding.explode` is honoured for `multipart/form-data` (#38). An array sent
  the unexploded way — one part carrying a comma-separated list — decoded as a
  single element. The form decoder already read `explode`.
- A parameter's `specPointer` names the declaration that carries it (#43).
  Path Item parameters and Operation parameters are merged for lookup but live
  at different pointers, and the merged index pointed at neither. The compiled
  parameter shape gains a `specPointer` key.
- A response range key matches whatever case the document spells it in (#44).
  `2xx` never matched `2XX`, and an unmatched status is reported by validating
  nothing at all.
- A contract error raised while validating a parameter is no longer reported as
  a request violation. `InvalidContract` extends `InvalidArgumentException`,
  which the parameter loop catches to turn deserialization failures into
  `request.parameter.serialization`; an unsupported schema was disguised as
  something the request did wrong.

## 0.4.0 — 2026-09-04

- `label` parameters with `explode: false` use `,` between array items, not `.`
  (#31). RFC 6570 spells the unexploded form `.3,4,5` and reserves the repeated
  dot for `explode: true`; both the parser and the serializer used `.` in either
  case, so a conforming client's `.3,4,5` deserialized to the single element
  `"3,4,5"` and failed its own schema. Object forms were already correct.
- `spaceDelimited` and `pipeDelimited` parameters recognise the percent-encoded
  delimiter (#32). The value was split on a literal `' '` or `'|'` before
  decoding, but a raw space cannot travel in a URI and `|` is outside the query
  character set, so any PSR-7 URI implementation delivers `%20` / `%7C` and the
  whole value came back as one element. Both wire forms are now accepted, the
  serializer emits `%20` for `spaceDelimited`, and it rejects a list item that
  contains its own delimiter — the style has no escape for one. That limitation
  holds in both directions: a separator and an encoded item character are the
  same octets on the wire, so `color=blue%7Cblack` is read as two values and
  never as the single value `blue|black`.

  Both bugs were symmetric between this package's parser and serializer, so the
  round-trip property could not see them; the styles are now pinned by exact
  wire fixtures taken from the OpenAPI style-examples table.

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
