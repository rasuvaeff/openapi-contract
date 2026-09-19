# AGENTS.md — openapi-contract

Guidance for AI agents working on this package. Read before changing code.

## What this is

A framework-neutral OpenAPI contract validator for complete PSR-7 exchanges,
in namespace `Rasuvaeff\OpenApiContract`, published as
`rasuvaeff/openapi-contract` (0.x). The feasibility phase is closed — the
backend decision and the executable corpus status are recorded in
[FEASIBILITY.md](FEASIBILITY.md). The public API (`Contract`, `Limits`, `Operation`,
`MatchedOperation`, `ValidationResult`, `Violation`,
`ValidationResultFormatter`) is documented in README EN/RU and `llms.txt`;
milestone types stay under `Internal\` and carry `@internal` — never let one
appear in a public signature.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Unsupported contract semantics fail closed.** Never ignore an assertion
   keyword, dialect, reference kind, or serialization style merely because a
   backend accepts the rest of the document.
4. **Preserve the public contract.** Update README EN/RU, llms.txt, examples,
   and tests with any API change.

## Commands

No PHP or Composer on the host. Run through Docker:

```bash
make install
make cs-fix
make build
make rector
make mutation
make release-check
```

## Invariants & gotchas

- OAS 3.0 Schema Objects are not Draft 2020-12 schemas. Keep the 3.0
  normalization path separate from the native 3.1 path.
- Unknown `jsonSchemaDialect` and `$schema` values are errors, not annotations.
- `fromFile()` resolves multi-file documents: relative `$ref`s to sibling
  JSON/YAML files inside the entry file's directory tree, with traversal,
  scheme, and symlink escapes rejected. `fromArray()`/`fromJson()` have no
  trusted filesystem root and accept same-document references only.
- Response selection is exact status, then uppercase range (`2XX`), then
  `default`; do not emit body/header errors if no Response Object matched.
- Validation backends are implementation details. Public diagnostics and
  operation models must not expose backend-specific classes.
- `multipleOf` is not the backend's: `Internal\Schema\Backend\Parser` compiles
  every schema to a Draft 2020-12 whose `multipleOf` parser is
  `DecimalMultipleOfKeywordParser`, judging on the shortest round-trip
  decimals (`DecimalMultiple::holds()`) rather than on the parsed doubles.
  The backend's own keyword answered differently with and without
  `ext-bcmath` (#151); a keyword parsed twice is judged twice, so the parser
  is replaced in the draft, never appended beside it.
- Diagnostics are bounded and redacted. Credentials never belong in rendered
  expected/actual values.
- Use `property-testing-testo` for algebraic laws, round-trips, generation
  postconditions, and corpus replay. Transport, factory, and credential
  interaction contracts belong in `property-testing-openapi`, where public
  adapters use `understudy-testo`. Backend compatibility fixtures use real
  backend objects.
- Code uses `declare(strict_types=1)`, internal types carry
  `@internal`, and public types carry `@api`.
- `tests/fixtures/generated-corpus/requests.json` is **generated, never
  hand-edited**. `rasuvaeff/property-testing-openapi` depends on this package,
  so it cannot be a dev dependency here; the corpus is how its generated
  traffic reaches this suite at all. It records that generator's intent — this
  case was built valid, this one was built to break `enum` in the query — not
  our verdict, because a corpus of our own verdicts pins today's bugs as
  expected behaviour. Re-record with `bin/record-openapi-corpus` from the
  monorepo root when the generator's zoo grows, and `--check` on the same
  command says whether you need to without rewriting anything. A case whose
  verdict moved is a question about this package, and re-recording answers it
  by deleting it — read the case first.
- `tests/Differential/` runs in the **Unit** suite on purpose. The suite
  convention puts non-unit tests under `tests/Integration/`, but those are the
  ones that need a server and are skipped by env; the league and cebe
  differentials are hermetic — they need nothing but dev dependencies, and
  they are the only thing that catches this package agreeing with itself while
  disagreeing with every other reader of the same document. Moving them out of
  the default suite would take them out of CI.
- **`Operation` is an output type with a public constructor.** Nothing
  public validates a hand-built operation: the shapes the constructor takes
  are the compiler's output rather than a checked input, and the validators'
  defensive branches exist because a hand-built one can still reach them. The
  constructor is `@api` all the same — consumers build operations by hand in
  their tests, so it was frozen in practice — and it is append-only: a minor
  may add a defaulted parameter at the end, never reorder or remove one, and
  callers use named arguments. `CompiledParameter`, `CompiledRequestBody`
  and `CompiledResponses` are read shapes whose variance is declared:
  consumers read them, minors may add keys to them. The two body shapes
  promise exactly what `DocumentCompiler::assertContent()`/`assertHeaders()`
  check — widen the shape when you widen the check, not before.
- **Every document schema is compiled at load time.** `Contract::__construct`
  walks `OperationSchemas::of()` and compiles each schema in the direction
  the validators will read it in, and `SchemaValidator::compiledSchema()`
  parses every backend node eagerly (`assertParsed()`), because the backend
  otherwise parses a nested member on the first value that reaches it and
  wraps a parse error into a schema that throws when validated. Add a new
  position the validators read a schema at → add it to `OperationSchemas`,
  or the load-time guarantee in README silently stops covering it.
- **The directional rewrite drops `required` entries, not properties.**
  `SchemaValidator::effectiveSchema()` — exported as
  `SchemaCheck::effective()` — keeps a `readOnly`/`writeOnly` property
  declared and typed and removes only its `required` entry for the foreign
  direction. `property-testing-openapi` builds values against this rewrite;
  changing what it does is a verdict change for the generator too.
- **A budget is a policy, not a verdict.** `Limits` carries them and every
  factory takes one. `*.body.too_large` says the validator declined to read a
  body; it must never be reworded into a claim that the message is wrong, and
  a third `ValidationResult` state is deliberately not the answer — it would
  change what `isValid() === false` means for every existing consumer.
- `examples/` is part of the public contract; every listed script must run.
- CI actions stay SHA-pinned with read-only permissions and checkout
  credentials disabled.

## Mutation gate: known equivalent classes

**A class that appears in no `#[Covers]` gets no mutants at all.** Infection is
fed by testo's codecov map, which is built from those attributes, so an
unlisted class is not "covered by whatever executes it" — it is invisible, and
so is every regression in it. The body decoders, the value decoder, the
message-reading trait and the response selection value object were in that
position until the 1.0 preparation wave; adding them raised the mutant count
by roughly 470 and dropped the measured MSI by two and a half points before
the tests caught up. Add the `#[Covers]` when you add a class, and check the
mutant total after — a number that did not move is the symptom.


`composer mutation` (minMsi 92) leaves a stable set of escaped mutants that
are equivalent by analysis — do not chase them, and re-classify anything new:
injective key/template concatenations (a reordered or trimmed key that stays
injective changes nothing observable), unreachable defensive guards kept for
psalm typing — including the shape checks in `ResponseValidator`'s header loop,
which the compiler now rejects at load time and which only a hand-built
`Operation` can still reach — `explode()` limit bumps where only `[0]` is read,
`array_pad` on inputs that always split into two parts, throw-order swaps that surface
the identical message from a later check, opis parser options that gate
keywords the schema compiler already rejects fail-closed, the DocumentGraph
filesize pre-check whose removal falls through to the identical post-read
byte-budget throw, the scheme-detection regex anchor whose removal only
widens an already fail-closed rejection (a colon in a later path segment),
and the canonical-delimiter index in `ParameterCodec::parseDelimitedQuery()`
(every wire form of a delimiter is folded to the chosen one before the
split, so any element of the list produces the same partition), the
`array_values()` calls over a Path Item's and an Operation's `parameters`
in `DocumentCompiler::parameters()` (a JSON array decodes to a list, so
the re-index has nothing to change and only the pointer index would move),
and — since the message-reading trait joined the coverage map — the whole
chunking arithmetic of `MessageReading::bodyContents()`: a larger `$remaining`
or a wider `read()` window still lands on the same `> $maxBytes` check, and
`break` against `continue` on an at-eof empty chunk differ only in re-testing
the `while` condition that is already false. Route bucketing escapes in the
widening direction only: the always-scanned bucket is a superset, so a mutant
that puts more routes into it loses the optimization and not a verdict — a
mutant that narrowed bucketing would change selection, and those are killed.
The keyword test on the
single-subschema branch of `SchemaValidator::effectiveSchema()` (`items` or
`additionalProperties`) escapes under negation because `properties` is
consumed by the branch above it and every other keyword the loop visits is a
list, so the widened condition never reaches a node it would rewrite
differently. The two `return [];`
guards in `OperationSchemas` that answer a non-array `content` or `headers`
escape under removal because the `foreach` they protect then iterates a
non-array — a PHP warning and the same empty result — and the walk's
`(string) $name` casts, like the decoder's, only spell a numeric key PHP
normalises back. The media-type selection helpers
in the same trait escape for the reasons above: the rank sentinel is below
every specificity, the key/definition type guard is reachable only through a
hand-built `Operation`, and the strict `>` is untestable because no two
declarations of equal specificity can match one media type.

## When you finish

Run `composer build`, `composer rector`, and `git diff --check`. Run mutation
when source validation or selection behavior changes.
