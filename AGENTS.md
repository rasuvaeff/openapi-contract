# AGENTS.md — openapi-contract

Guidance for AI agents working on this package. Read before changing code.

## What this is

A framework-neutral OpenAPI contract validator for complete PSR-7 exchanges,
in namespace `Rasuvaeff\OpenApiContract`, published as
`rasuvaeff/openapi-contract` (0.x). The feasibility phase is closed — the
backend decision and the executable corpus status are recorded in
[FEASIBILITY.md](FEASIBILITY.md). The public API (`Contract`, `Operation`,
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
- `examples/` is part of the public contract; every listed script must run.
- CI actions stay SHA-pinned with read-only permissions and checkout
  credentials disabled.

## Mutation gate: known equivalent classes

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
split, so any element of the list produces the same partition), and the
`array_values()` calls over a Path Item's and an Operation's `parameters`
in `DocumentCompiler::parameters()` (a JSON array decodes to a list, so
the re-index has nothing to change and only the pointer index would move).

## When you finish

Run `composer build`, `composer rector`, and `git diff --check`. Run mutation
when source validation or selection behavior changes.
