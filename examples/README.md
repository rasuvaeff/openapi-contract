# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `validate-exchange.php` | Loading a document, operation matching, exchange validation, violations, `assertValid()` | No |
| `gate-a-request.php` | The shape a PSR-15 middleware takes — validate in, hand on, validate out — over a multi-file `fromFile()` document, with `ValidationResultFormatter` rendering what failed | No |
| `budgets.php` | `Limits`: why `*.body.too_large` is a refusal to read rather than a verdict, and what the document budgets bound | No |
| `validate-webhook.php` | The receiving side: a 3.1 `webhooks` document, `validateWebhook()` for a delivery and for the exchange, and body diagnostics that name the failing member, its keyword, and the discriminator branch they followed | No |

`openapi/pets.yaml` and `openapi/schemas/pet.yaml` are the multi-file document
`gate-a-request.php` loads: a relative `$ref` to a sibling file inside the entry
file's directory tree.

Run from the package root after `make install`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/validate-exchange.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/gate-a-request.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/budgets.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/validate-webhook.php
```
