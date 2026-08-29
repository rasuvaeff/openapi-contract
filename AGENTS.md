# AGENTS.md — openapi-contract

Guidance for AI agents working on this package. Read before changing code.

## What this is

A framework-neutral OpenAPI contract validator for complete PSR-7 exchanges,
in namespace `Rasuvaeff\OpenApiContract`. The package is in feasibility work;
do not promote internal milestone types to public API before the backend,
dialect, and diagnostics fixtures prove the shape.

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
- Only same-document fragment references are eligible for v0.1.
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
- Code uses `declare(strict_types=1)`, internal feasibility types carry
  `@internal`, and public types carry `@api`.
- `examples/` is part of the public contract; every listed script must run.
- CI actions stay SHA-pinned with read-only permissions and checkout
  credentials disabled.

## Mutation gate: known equivalent classes

`composer mutation` (minMsi 92) leaves a stable set of escaped mutants that
are equivalent by analysis — do not chase them, and re-classify anything new:
injective key/template concatenations (a reordered or trimmed key that stays
injective changes nothing observable), unreachable defensive guards kept for
psalm typing, `explode()` limit bumps where only `[0]` is read, `array_pad`
on inputs that always split into two parts, throw-order swaps that surface
the identical message from a later check, and opis parser options that gate
keywords the schema compiler already rejects fail-closed.

## When you finish

Run `composer build`, `composer rector`, and `git diff --check`. Run mutation
when source validation or selection behavior changes.
