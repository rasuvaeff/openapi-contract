# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- **Changed.** The refusal of a document without `paths` says why: a 3.1
  document may legally declare only `webhooks` or `components`, and it is this
  package that has nothing to validate, not the document that is malformed.
  The README now also draws the line the package draws — it checks what a
  verdict about a message depends on, and leaves what only affects
  documentation to a linter.

- **Fixed.** The same parameter declared twice inside one `parameters` list is
  rejected. A parameter is unique by name and location, and reading the last
  declaration silently dropped whichever was stricter — a document could
  narrow a parameter and have the narrowing disappear. Across the two lists
  the Operation's declaration still replaces the Path Item's, which is the
  specification's own rule; header names are compared case-insensitively, so
  `X-Trace` and `x-trace` are one parameter.

- **Fixed.** A header parameter named `Accept`, `Content-Type` or
  `Authorization` is ignored, as both specifications say in the same words.
  Enforcing it turned a conforming request into three violations, and a
  document declaring `Authorization` as a header parameter — a common habit —
  into one that demanded the header on every request.

- **Fixed.** Under OAS 3.0, `requestBody` on `GET`, `HEAD` and `DELETE` is
  ignored, which is what that dialect says consumers SHALL do. OAS 3.1 permits
  it instead and only advises against it, so a 3.1 document is validated as
  before.

- **Fixed.** `$ref` is resolved only where it is a keyword. The resolver walked
  every array and read any map carrying a `$ref` member as a reference, so a
  document that merely *showed* such a payload — in an `example`, in an Example
  Object's `value`, in a Schema Object's `default`, or in a specification
  extension — was refused; and where the pointer did resolve, the example the
  document declared was silently replaced by a piece of the document. Those
  positions carry the author's data and are now left exactly as written. An
  Example Object reached through `$ref` is still a reference and still
  resolves.

- **Fixed.** A response header declaring the boolean schema `false` fails
  closed when the header arrives, as a body declaring it always has. It used to
  read as "no schema here" and assert presence only.

- **Fixed.** The request path comes from the URI rather than from
  `parse_url()` on the rendered URI, which reads a scheme where a relative
  path's first segment carries a colon.

- **Added.** The README says what the package does not do: declared `security`
  is compiled but never enforced — a request missing its credential validates
  clean — and `allowReserved` is an annotation validation does not read, like
  `example`/`examples`.

- **Fixed.** A trailing slash is part of the path. `/pets` and `/pets/` are
  different resources to RFC 3986 and to every router an application sits
  behind; matching trimmed both ends of both sides, so a document declaring
  both compiled and then answered both requests with the same operation,
  leaving the other unreachable.

- **Fixed.** The diagnostic for an unmatched request carries the path, not the
  whole URI. The query string is where an API key travels, and the rendered
  message is what `ContractViolation` carries into an application's log — the
  redaction that guards `actual` used to find the credential inside
  `instancePath` and redact the line below while printing this one.

- **Fixed.** A Schema Object is checked all the way down where the document is
  compiled, not on the first message that happens to reach a subschema. An
  `items` or `properties` member the package cannot read used to survive
  compilation and then surface as `response.header.serialization` or
  `request.body.decode` — the document's defect blamed on the traffic, and
  answered differently depending on which of four places read it first. The
  three validators that still catch a deserialization failure now re-throw
  `InvalidContract` before turning it into a violation, for the one path left:
  an `Operation` built by hand rather than compiled.

- **Fixed.** A parameter name that occurs more than once, where its style
  admits a single value, is `request.parameter.duplicate` instead of the first
  occurrence. `?n=5&n=999` is a well-formed query whose meaning depends on the
  runtime — PHP keeps the last occurrence in `parse_str()`, `$_GET` and every
  PSR-7 `getQueryParams()`, Go keeps the first, Node keeps both — so reading
  the first let a request satisfy the contract with one value and hand the
  application another, which is a silent bypass of every scalar assertion.
  Exploded lists are untouched: repeating the name is what that style means.

- **Internal.** The body decoders, the value decoder, the message-reading
  trait and the response selection value object are named in `#[Covers]` at
  last. Infection is fed by those attributes, so an unlisted class gets no
  mutants at all: roughly 470 of them — every regression the two body decoders
  could suffer — were invisible to the gate. The suite gained the cases those
  mutants asked for; the measured MSI is back above the gate on the honest
  population.

- **Internal.** The parameter codec's property tests gained the example phase
  they were missing: the eighteen style/explode combinations now run
  deterministically before the random phase instead of waiting on the
  generator to draw them, and the eighteen `Classify::cover` gates sit at a
  threshold their own distribution can actually hold.

- **Added.** The complete set of `Violation` codes is written down — in both
  READMEs and `llms.txt` — with a test that pins it against what the source
  actually emits, so a new or renamed code is a deliberate edit rather than a
  side effect. The documents also say what is stable: the code is, the
  formatter's message text is a diagnostic and may be reworded.

- **Added.** `Operation::responseFor()`, public since 0.2.0, is documented.
  The parameter merge (Operation over Path Item, last declaration wins within
  one list), the host-agnostic matching of a request that carries no
  authority, and the `pattern`/`preg_match` threat model are stated as
  contract rather than left to be read off the tests.

- **Fixed.** Truncated diagnostics no longer end in a partial `\uXXXX` escape.
  Every value the formatter bounds is `json_encode` output, so the cut cannot
  split a UTF-8 sequence — but it could land inside an escape and leave
  `\u04` where a character was meant.

- **Internal.** `rasuvaeff/understudy` moved to the `^0.9` line.

- **Added.** `ContractException`, the type every exception this package raises
  implements. "Handle anything the contract can throw" had to be written as
  the current list of classes and rechecked on every upgrade, or widened to
  `\InvalidArgumentException`, which catches this package together with
  everything else on the stack. The concrete parents are unchanged.

- **Fixed.** A status outside 100-599 is a violation
  (`response.status.invalid`), not a bare `InvalidArgumentException` out of
  `validateResponse()`, and `Operation::responseFor()` answers `null` for one
  instead of raising where it answers `null` for an undeclared 418.

- **Fixed.** A body stream that reports it is not finished and then reads
  nothing is a violation (`request.body.unreadable` /
  `response.body.unreadable`), like the non-seekable stream and the oversized
  body next to it. It used to leave the public validate methods as a bare
  `\RuntimeException`.

- **Fixed.** A `content` map is matched by specificity instead of by the order
  its keys happen to be written in. A `*/*` entry above an exact media type
  used to decide the body's schema, and moving the two lines changed what the
  document said — while OpenAPI gives a map's key order no meaning at all.
  Exact `type/subtype` now wins over `type/*+suffix`, which wins over `type/*`,
  which wins over `*/*`; only equally specific keys fall back to declaration
  order.

- **Fixed.** `$ref` siblings are read by the dialect the document declares and
  by the position the node sits in. Every reference used to be merged with its
  siblings winning, which is not what any of the four cases says: OAS 3.0
  ignores added properties on a Reference Object — and a 3.0 Schema Object
  *is* one — while 3.1 keeps only `summary`/`description` on a Reference
  Object and makes a Schema Object's siblings apply in addition to the
  reference, as JSON Schema 2020-12 requires of an applicator. A sibling
  `type` next to a `$ref` used to silently replace the referenced constraint
  in a 3.0 document; it is ignored now, and in 3.1 `{$ref: X, maximum: 10}`
  compiles to the conjunction of both instead of dropping whichever the merge
  overwrote.

- **Fixed.** A declaration this package cannot read is now rejected where it
  is written, instead of being read as "there is nothing to check here". A
  `requestBody` that is not an object used to compile to no body at all while
  the response side rejected the same shape; `parameters` that were not a list
  vanished; a Media Type Object, `encoding`, `headers` or Schema Object of an
  unreadable shape was skipped at request time, and the very same document
  raised a bare `InvalidArgumentException` out of `validateRequest()` while
  `validateResponse()` called the body valid. All of them are `InvalidContract`
  from `fromArray()`/`fromJson()`/`fromFile()` now, and the two directions read
  one document the same way. A schema this package cannot hand to the backend
  at all — a value JSON cannot encode, which is how YAML's `.nan` and `.inf`
  arrive — is rejected there too, rather than surfacing as a raw `JsonException`
  on the first request that happened to use it.

- **Fixed.** A boolean field written as a string (`required: "true"`,
  `explode: "yes"`) is rejected instead of silently reading as its default,
  which made a required parameter optional.

- **Fixed.** A document whose `paths` produce no operation at all — path items
  with no method, or only `x-` keys — is rejected. Such a contract answered
  `UnknownOperation` to every request, which reads as "this request is wrong"
  when what is wrong is the document.

- **Fixed.** A YAML file that does not parse leaves `fromFile()` as
  `InvalidContract` naming the document, with the parser's exception as
  `previous`. symfony/yaml's `ParseException` used to escape as itself — a
  third-party type on a public exit, from an optional dependency.

- **Changed.** A response `content` map with nothing in it now means "no media
  type is declared", so a body that arrives under one is reported as
  undeclared. It used to skip body validation entirely.

- **Internal.** The generated corpus is re-recorded against
  `property-testing-openapi` at the header decision: 287 cases over 20
  operations, up from 274 over 19. The new operation is `headers.get` — a
  scalar, a list and an exploded object header — which the corpus could not
  carry before, because the zoo had no header parameter at all and both
  packages percent-encoded, so they agreed with each other and with nobody
  else. One `uploads.create` case changed shape: the generator no longer
  builds a multipart text part with whitespace on its edge, since the parsers
  in the wild trim it and the value the application receives would not be the
  value the case recorded. No case changed its verdict.

- **Internal.** The generated corpus gains the three cases that close its one
  known blind spot: a multipart part sent under a media type its
  `encoding.contentType` does not allow (290 cases, up from 287). Neglecting
  that keyword is fail-open — a validator that reads it and ignores it accepts
  every valid case unchanged — so replaying the corpus against such a validator
  was green, and no amount of valid traffic could have said otherwise.
  `property-testing-openapi` can now build the contradiction, and this records
  it. Verified by making the check fail open again on the working tree: the
  replay turns red on `encoded.create/part-content-type/1`.

## 0.7.0 — 2026-09-05

- **Added.** A conformance replay of generated traffic. The differentials in
  this package have always been fed by a hand-written corpus of a dozen
  requests, which bounds what they can disagree about — every finding of the
  0.6.0 wave lived just outside those dozen.
  `rasuvaeff/property-testing-openapi` builds requests from a document and
  knows by construction whether each is meant to pass; it depends on this
  package, so it can never be a dev dependency here, and a recording is the
  only way its traffic reaches this suite. 274 cases over 19 operations across
  both OAS versions now replay from
  `tests/fixtures/generated-corpus/requests.json`, asserting the verdict the
  case was built for and, when it was built invalid, that the rejection lands
  where the misuse was planted rather than somewhere convenient. What is
  recorded is the generator's intent, not our own verdict — a corpus of the
  latter would have faithfully pinned all four 0.6.0 bugs as correct
  behaviour. Verified by replaying it on 0.5.1: the `bounded.create` document
  will not compile and a valid `numeric.create` body is rejected. A failing
  case names the version the corpus was recorded against, so a moved
  verdict reads as a question about this package rather than as an
  unexplained disagreement.

- **Changed.** A header parameter is read as it was sent, not
  percent-decoded (#66). OAS says a header uses `style: simple` and simple
  style is RFC 6570 expansion, which does define an encoding — but HTTP treats
  a field value as opaque octets, no client in the wild escapes one, and the
  two mistakes are not symmetric: decoding a value nobody encoded corrupts it
  silently, while leaving an encoded value alone only makes a length or
  pattern assertion stricter than its author meant. `X-Path: /a%20b` used to
  become `/a b`, and `X-Discount: 50%` a broken escape. Decoding now happens
  where an encoding provably exists — path, query, cookie — and nowhere else.
  The price is named rather than hidden: a header value can no longer carry
  its own style delimiter, because there is no escape left for it. The change
  applies to response headers too, and closes the one live disagreement the
  generated differential in `property-testing-openapi` found against
  `league/openapi-psr7-validator` — the two now agree on the same request.

## 0.6.0 — 2026-09-05

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
- **Fixed.** `encoding.contentType` is honoured for
  `application/x-www-form-urlencoded` bodies (#55). It was neither applied nor
  rejected, so a property declared `application/json` arrived as a string,
  failed its own object schema, and was reported as `request.body.schema` — a
  valid request called invalid, with a message pointing away from the cause.
  A JSON media type is now decoded and validated against the property schema;
  any other is validated as the string it already is, the rule an undecoded
  body already follows.
- **Fixed.** A declared multipart part header is validated against its schema,
  not merely checked for presence (#58). The constraint was stated by the
  document and enforced by nothing, while the same declaration on a response
  header was fully validated. A part header is read with the `simple` style,
  like a request header parameter; a `content`-form or non-`simple` declaration
  fails closed rather than passing an unchecked value through.
- **Fixed.** A path template whose placeholder shares its segment with literals
  — `/report.{format}`, `/v{version}/items`, `/{a}-{b}` — is matched (#56). Such
  a path compiled and then matched nothing at all, so the operation was
  unreachable, and a request that literally equalled the template was blamed for
  a missing parameter instead. Concrete paths still win over templated ones, a
  decoded separator still cannot escape a segment, and the literal runs are
  matched as written.
- **Fixed.** A parameter violation renders its `actual` value again (#63).
  `redactsActual()` returned true for `header`, `cookie`, `query` and `body` —
  every location a violation normally carries — so `expected` was printed in
  full, schema and all, beside an `actual` that always said `[redacted]`. The
  class already carried a name-based rule written for exactly those locations
  and unreachable behind the location check. It decides now, and it is applied
  to container members too, so a credential sitting under a member name the
  instance path does not reach is still replaced. Bodies stay redacted
  wholesale: their member names belong to the application, and a whole-body
  violation has the instance path `$`.
- **Changed.** Schema compilation is cached per `Contract` instance, keyed by
  schema, direction and dialect. The directional rewrite, the JSON round trip
  and the backend's parse ran on every validated message, which made
  `fromArray()` a parser and `validateRequest()` the compiler. Measured on a
  document with three parameters and a twenty-property body,
  `validateRequest()` went from 1.173 ms to 0.067 ms per call. The new
  `ValidateRequestBench` measures the hot path; the benchmark suite covered
  only response selection before.

- **Added.** `response.body.missing` — a response that declares a schema and
  arrives with an empty body is a violation (#57), the mirror of
  `request.body.missing`. Checking only the bodies that arrived meant the one
  failure contract testing exists to catch, an endpoint answering 200 with
  nothing, was the one it passed. Excluded: `204`, `304`, every response to a
  `HEAD` request, and a media type entry declaring no schema or the
  unconstrained boolean one.

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
