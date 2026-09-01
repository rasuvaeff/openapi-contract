<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract;

use Rasuvaeff\OpenApiContract\Internal\Response\ResponseSelector;
use Rasuvaeff\OpenApiContract\Internal\Response\SelectedResponse;

/**
 * Immutable compiled OpenAPI operation.
 *
 * The optional `example`/`examples` parameter keys are present exactly when
 * the document declares them; they stay annotations for validation and feed
 * the deterministic example phase of the generator package.
 *
 * @psalm-type CompiledParameter = array{
 *     name: non-empty-string,
 *     in: 'path'|'query'|'header'|'cookie',
 *     required: bool,
 *     style: string,
 *     explode: bool,
 *     allowReserved: bool,
 *     schema: array<string, mixed>,
 *     example?: mixed,
 *     examples?: array<string, mixed>,
 * }
 *
 * @api
 */
final readonly class Operation
{
    /**
     * @param list<CompiledParameter> $parameters
     * @param array<array-key, mixed> $requestBody
     * @param array<array-key, mixed> $responses
     * @param list<string> $serverBases
     * @param list<array<string, list<string>>> $security
     * @param list<array{scheme: null|non-empty-string, host: null|non-empty-string, port: null|int, base: non-empty-string}> $servers
     *        Full effective server model (operation > path > root precedence,
     *        variables substituted with their defaults). Contract compilation
     *        always fills it; `$serverBases` stays as the v0.1 base-path
     *        projection of the same list.
     */
    public function __construct(
        public string $key,
        public ?string $operationId,
        public string $method,
        public string $path,
        public array $parameters = [],
        public array $requestBody = [],
        public array $responses = [],
        public array $serverBases = ['/'],
        public array $security = [],
        public array $servers = [],
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
