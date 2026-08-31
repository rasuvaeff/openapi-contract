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
`UnsupportedVersion`; неизвестный JSON Schema dialect, remote-ссылки,
неоднозначные path templates, дубли operation identity и малформенные формы
документа — `InvalidContract`; parameter `content` и неподдержанные styles —
`UnsupportedSerialization`. Каждый placeholder в path template обязан иметь
effective-параметр `in: path` с тем же именем и явным `required: true`; лишние
path-параметры отвергаются при компиляции контракта.

`fromFile()` дополнительно разрешает относительные `$ref` на соседние
JSON/YAML файлы. Каждый referenced-файл обязан оставаться внутри дерева
каталога entry-файла: абсолютные пути, URI-схемы, percent-encoded пути,
traversal и symlink escape отклоняются до какого-либо чтения, а ошибки
резолюции показывают пути относительно document root. У `fromArray()` и
`fromJson()` нет доверенного filesystem root — они принимают только
same-document ссылки. Документы ограничены бюджетами: размер в байтах, JSON
depth, глубина `$ref`, общий node budget, а для многофайловых документов —
общие на весь граф бюджеты числа файлов и байтов.

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
`METHOD /path`. Скомпилированные параметры сохраняют объявленные
`example`/`examples` (с разрешёнными `$ref`) как аннотации: валидация их
игнорирует, а пакет генераторов использует в детерминированной
example-фазе. `MatchedOperation` несёт операцию и сырые path-параметры из
URI. Matching учитывает server base paths, предпочитает конкретные пути
шаблонным, декодирует каждый сегмент ровно один раз и отвергает
декодированные разделители, выводящие значение из template slot.

Servers компилируются в полную модель (`Operation::$servers`): scheme, host,
port и base path с precedence operation > path > root и подстановкой
defaults у server variables. Absolute server ограничивает каждый компонент
URI, который запрос реально несёт, — нормализованные scheme, host и
effective port (`443` для `https`, `80` для `http`), поэтому одинаковый path
на двух hosts выбирает только правильную операцию; relative server и
path-only request URI остаются host-agnostic. Необъявленные переменные,
отсутствующий или вне-enum default, неподдержанные схемы и userinfo/query/
fragment в server URL отвергаются fail-closed при компиляции.
`Operation::$serverBases` остаётся v0.1-проекцией base paths того же списка.
Когда path объявлен, но ни один server authority не совпал, валидация
возвращает `request.server.mismatch`, а не `request.operation.unknown`.

### Валидация exchanges

```php
use Rasuvaeff\OpenApiContract\ValidationResultFormatter;

$result = $contract->validateRequest($request);
$result = $contract->validateExchange($request, $response);
$result = $contract->validateResponse('pets.get', $response);

$result->assertValid(); // бросает ContractViolation при нарушениях
$diagnostics = (new ValidationResultFormatter())->format($result);

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

`validateResponse()` проверяет fixture ответа по identity операции без живого
request. Неизвестный ключ операции даёт одно структурированное нарушение
`response.operation.unknown`.

Request body с `application/x-www-form-urlencoded` декодируется по тем же
form-правилам, что и query-параметры. `multipart/form-data` поддерживает
ограниченный разбор частей, JSON и binary parts, повторяющиеся части-массивы и
`encoding` с content type/required headers для свойства. Неподдержанные styles,
битые boundaries, повтор scalar parts и неверное содержимое parts отвергаются
fail-closed как `request.body.decode`.

При валидации body seekable PSR-7 stream читается с начала, после чего его
исходная позиция восстанавливается, в том числе при ошибке чтения. Если body
нужно проверить, но stream не поддерживает seek, validator не читает его и
возвращает `request.body.non_seekable` или `response.body.non_seekable`.
Body больше `Contract::MAX_MESSAGE_BODY_BYTES` (1 MiB) даёт соответствующее
нарушение `request.body.too_large` или `response.body.too_large`.
`ValidationResultFormatter` выводит все нарушения в стабильном порядке и
ограничивает поля, глубину, число элементов и expected/actual. Actual values
из header, cookie, query и полей с чувствительными именами редактируются;
`ContractViolation` использует тот же полный вывод.

## Безопасность

Неподдерживаемая семантика контракта никогда не игнорируется: версии,
диалекты, ссылки, стили сериализации и schema assertions вне support matrix
отвергаются fail-closed. Пользовательские документы и тела сообщений
читаются с byte- и JSON-depth-бюджетами; expected/actual в диагностике
рендерятся в ограниченной форме без раскрытия credential-параметров.

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
`league/openapi-psr7-validator`. Второй закоммиченный корпус прогоняет те же
многофайловые деревья документов через `cebe/php-openapi` (dev-only oracle
для OAS 3.0) и закрепляет осознанные дивергенции: наш бюджет глубины
отвергает длинные цепочки, которые oracle инлайнит, а cross-file цикл,
подвешивающий oracle, у нас — быстрая стабильная ошибка. Решение по backend
и статус executable corpus записаны в [FEASIBILITY.md](FEASIBILITY.md).

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
