# Feasibility milestone 0

This package is still pre-release. The first executable corpus covers the two
schema dialects and response selection rules described in the plan.

## Backend decision

Opis JSON Schema 2.6 is the selected runtime schema backend. The compiler
keeps OAS 3.0 and OAS 3.1 normalization separate, then emits a bounded
Draft 2020-12 object for Opis with its non-standard extensions disabled.

League OpenAPI PSR-7 Validator 0.24 remains a development-only differential
fixture. Its OAS 3.1 path accepted the values 2 and 4 for a schema with
type [integer, null], exclusiveMinimum 2, and const 3.

Opis accepts only 3, which is required for contract validation. The
BackendFeasibilityTest keeps this observation executable. It is not a claim
that League is generally unusable; its PSR-7 operation matching and message
validation remain useful future comparison fixtures.

## Current exit status

- OAS 3.0 nullable and boolean exclusive bounds normalize to Draft 2020-12.
- OAS 3.1 type unions and numeric exclusive bounds remain native.
- Unknown schema dialect values fail closed.
- OAS 3.0 boolean schemas fail closed.
- Response selection is exact status, then NXX, then default.
- Testo property tests cover generated numeric boundary distribution.
- understudy-testo is registered for future PSR boundary interaction tests;
  no manual double is used in backend compatibility fixtures.

The PSR-15 fixture, request serialization round-trip, bounded references, and
runner adapters are not complete and remain subsequent milestones.
