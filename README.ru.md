# rasuvaeff/openapi-contract

Framework-neutral валидация PSR-7 request/response exchanges по контрактам
OpenAPI 3.0 и 3.1.

> Используете AI-ассистента? [llms.txt](llms.txt) — компактный
> самодостаточный API-справочник пакета.

> Статус: pre-release. Публичный API ниже достаточно стабилен для
> dogfooding; первый тег выйдет релизным поездом вместе с
> property-testing-openapi.

## Требования

- PHP 8.3 – 8.5
- реализации `psr/http-message` для валидируемых exchanges
- `symfony/yaml` только для загрузки YAML-документов (suggest, не require)

## Установка

```bash
composer require rasuvaeff/openapi-contract
```

## Использование

### Загрузка контракта

`Contract` — immutable скомпилированный документ:

```php
use Rasuvaeff\OpenApiContract\Contract;

$contract = Contract::fromArray($document);
$contract = Contract::fromJson($json, source: 'openapi.json');
$contract = Contract::fromFile('openapi.yaml'); // нужен symfony/yaml
```

Загрузка fail-closed: неподдерживаемая версия OpenAPI бросает
`UnsupportedVersion`; неизвестный JSON Schema dialect, нелокальные ссылки,
неоднозначные path templates, дубли operation identity и малформенные формы
документа — `InvalidContract`; parameter `content` и неподдержанные styles —
`UnsupportedSerialization`. Документы ограничены бюджетами: размер в байтах,
JSON depth, глубина `$ref` и общий node budget.

### Операции и matching

```php
foreach ($contract->operations() as $operation) {
    // Operation: key, operationId, method, path, parameters, requestBody,
    // responses, serverBases, security
}

$matched = $contract->match($request);        // MatchedOperation|null
$matched = $contract->requireMatch($request); // бросает UnknownOperation
$operation = $contract->operation('pets.get'); // бросает UnknownOperation
```

Identity операции — `operationId`, если он есть, иначе стабильный
`METHOD /path`. `MatchedOperation` несёт операцию и сырые path-параметры из
URI. Matching учитывает server base paths, предпочитает конкретные пути
шаблонным, декодирует каждый сегмент ровно один раз и отвергает
декодированные разделители, выводящие значение из template slot.

### Валидация exchanges

```php
$result = $contract->validateRequest($request);
$result = $contract->validateExchange($request, $response);

$result->assertValid(); // бросает ContractViolation при нарушениях

foreach ($result->violations as $violation) {
    // Violation: code, operation, location, instancePath, specPointer,
    // expected, actual, message
}
```

`ValidationResult` — immutable список `Violation` со стабильными кодами
(`request.parameter.missing`, `response.body.schema`, ...) и JSON Pointer в
OpenAPI-документ. Выбор ответа: точный статус, затем `NXX`-диапазон, затем
`default`; неизвестный статус не порождает вымышленных body/header
нарушений. `readOnly`/`writeOnly` применяются направленно. Root `security`
наследуется операциями, явный пустой список `security` делает операцию
анонимной, а получение credentials остаётся в пакете генераторов.

## Безопасность

Неподдерживаемая семантика контракта никогда не игнорируется: версии,
диалекты, ссылки, стили сериализации и schema assertions вне support matrix
отвергаются fail-closed. Пользовательские документы и тела сообщений
читаются с byte/depth-бюджетами; expected/actual в диагностике рендерятся в
ограниченной форме.

## Примеры

Runnable-скрипты — в [examples/](examples/README.md).

## Разработка

```bash
make install
make build
make release-check
```

Тесты используют property-based проверки законов и round-trip'ов
сериализации, а differential-корпус закрепляет согласие вердиктов с
`league/openapi-psr7-validator`. Решение по backend и статус executable
corpus записаны в [FEASIBILITY.md](FEASIBILITY.md).

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
