<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

use Rasuvaeff\OpenApiContract\Internal\Response\ResponseSelector;
use Rasuvaeff\OpenApiContract\Internal\Response\SelectedResponse;

/**
 * Immutable compiled OpenAPI operation: the read model a compiled contract
 * exposes, for consumers that render, generate or introspect operations.
 *
 * The optional `example`/`examples` parameter keys are present exactly when
 * the document declares them; they stay annotations for validation and feed
 * the deterministic example phase of the generator package.
 *
 * `CompiledParameter` is a read shape: consumers may import it and read it,
 * and a minor release may add keys to it. `allowReserved` is one such
 * hand-off — it cannot be derived from the schema, and a consumer that
 * renders a query value needs it to decide whether reserved characters are
 * percent-encoded. Validation never reads it, because after a message is
 * parsed an encoded reserved character can no longer be told from an
 * unencoded one.
 *
 * `CompiledRequestBody` and `CompiledResponses` are the Request Body Object
 * and the Responses Object as the compiler leaves them: every `$ref` on the
 * way to a schema resolved, `required` checked to be a boolean, `content`
 * keyed by media type, `encoding` and `headers` keyed by property and header
 * name, and every `schema` either a boolean or a keyword map — the empty map
 * being the unconstrained schema. The shapes are open: what the document
 * wrote beside those keys (`description`, `example`, extensions) is kept as
 * written, and a minor release may add keys. `CompiledResponses` is keyed by
 * the status code — as PHP reads it, so `"200"` is `int 200` — by the
 * `NXX` range, or by `default`.
 *
 * @psalm-type CompiledParameter = array{
 *     name: non-empty-string,
 *     in: 'path'|'query'|'header'|'cookie',
 *     required: bool,
 *     style: string,
 *     explode: bool,
 *     allowReserved: bool,
 *     schema: array<string, mixed>,
 *     specPointer: non-empty-string,
 *     example?: mixed,
 *     examples?: array<string, mixed>,
 * }
 * @psalm-type CompiledSchema = null|bool|array<string, mixed>
 * @psalm-type CompiledHeader = array{
 *     required?: bool,
 *     schema?: CompiledSchema,
 *     ...
 * }
 * @psalm-type CompiledMediaType = array{
 *     schema?: CompiledSchema,
 *     encoding?: array<string, array{headers?: array<string, CompiledHeader>, ...}>,
 *     ...
 * }
 * @psalm-type CompiledRequestBody = array{
 *     required?: bool,
 *     content?: array<string, CompiledMediaType>,
 *     ...
 * }
 * @psalm-type CompiledResponse = array{
 *     headers?: array<string, CompiledHeader>,
 *     content?: array<string, CompiledMediaType>,
 *     ...
 * }
 * @psalm-type CompiledResponses = array<array-key, CompiledResponse>
 *
 * @api
 */
final readonly class Operation
{
    /**
     * Compilation is how an operation is built, and the only path that
     * checks the shapes this constructor takes: the compiler's output is a
     * read model, not a validated input. The constructor is public API all
     * the same, because consumers build operations by hand in their tests —
     * with named arguments, which is what keeps that working: parameters
     * are appended, never reordered or removed, and every parameter after
     * `path` has a default.
     *
     * @param list<CompiledParameter> $parameters
     * @param CompiledRequestBody $requestBody the resolved Request Body
     *        Object; empty when the operation declares none (or when OAS 3.0
     *        tells consumers to ignore it)
     * @param CompiledResponses $responses the resolved Responses Object
     * @param list<array<string, list<string>>> $security
     * @param list<array{scheme: null|non-empty-string, host: null|non-empty-string, port: null|int, base: non-empty-string}> $servers
     *        Full effective server model (operation > path > root precedence,
     *        variables substituted with their defaults). Contract compilation
     *        always fills it; a hand-built operation may leave it empty,
     *        in which case the contract's matcher has no route for it.
     * @param SchemaDialect $dialect the dialect this operation's Schema
     *        Objects are written in, so a consumer holding the operation can
     *        check a value against one of them the way the contract does
     *        ({@see SchemaCheck}). Compilation fills it from the document's
     *        `openapi` version; a hand-built operation defaults to 3.1.
     * @param ?non-empty-string $webhook the `webhooks` map key this operation
     *        was compiled from, and `null` for a path operation. A webhook is
     *        a Path Item without a path: `$path` is empty, `$servers` is
     *        empty, and no path parameter is declared. Its identity is its
     *        `operationId` when present, otherwise `WEBHOOK <METHOD> <name>`.
     *
     * @api append-only: a minor release may add a defaulted parameter at the
     *      end; construct with named arguments.
     */
    public function __construct(
        public string $key,
        public ?string $operationId,
        public string $method,
        public string $path,
        public array $parameters = [],
        public array $requestBody = [],
        public array $responses = [],
        public array $security = [],
        public array $servers = [],
        public SchemaDialect $dialect = SchemaDialect::OpenApi31,
        public ?string $webhook = null,
    ) {}

    /**
     * The Response Object a concrete status resolves to — exact code, then
     * the `NXX` range, then `default` — as the same selection response
     * validation applies; `null` when the status is not declared.
     *
     * @return null|array{key: non-empty-string, definition: array<string, mixed>}
     */
    public function responseFor(int $status): ?array
    {
        $selected = (new ResponseSelector())->select($this->responses, $status);

        return $selected instanceof SelectedResponse ? ['key' => $selected->key, 'definition' => $selected->definition] : null;
    }
}
