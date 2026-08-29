# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `validate-exchange.php` | Loading a document, operation matching, exchange validation, violations, `assertValid()` | No |

Run from the package root after `make install`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/validate-exchange.php
```
