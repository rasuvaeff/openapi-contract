# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
