# rasuvaeff/openapi-contract

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/openapi-contract/v)](https://packagist.org/packages/rasuvaeff/openapi-contract)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/openapi-contract/downloads)](https://packagist.org/packages/rasuvaeff/openapi-contract)
[![Build](https://github.com/rasuvaeff/openapi-contract/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/openapi-contract/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/openapi-contract/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/openapi-contract/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/openapi-contract/actions/workflows/static-analysis.yml)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

[English version](README.md)

Framework-neutral валидация PSR-7 request/response exchanges по контрактам
OpenAPI 3.0 и 3.1.

> Используете AI-ассистента? [llms.txt](llms.txt) — компактный
> самодостаточный API-справочник пакета.

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

Объявления читаются строго, а не снисходительно. `requestBody`,
`parameters`, `content`, `encoding`, `headers` или Schema Object в форме,
которую пакет прочитать не может, — это `InvalidContract` на загрузке, а не
молча не проверяемая часть контракта; булево поле, записанное строкой
(`required: "true"`), отвергается, а не откатывается к значению по умолчанию;
схема со значением, которое JSON закодировать не может (`.nan` и `.inf` в
YAML), отвергается до того, как дойдёт до backend'а валидации; документ, чьи
`paths` не дают ни одной операции, отвергается, а не компилируется в
контракт, отвечающий `UnknownOperation` на любой запрос; а YAML-файл, который
не парсится, сообщается как `InvalidContract`, а не собственным типом
исключения парсера.

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
    // responses, serverBases, security, servers
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
декодированные разделители, выводящие значение из template slot. Placeholder
может делить сегмент с литералами (`/report.{format}`, `/v{version}/items`,
`/{a}-{b}`); литеральные части сопоставляются так, как записаны.

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

Параметры десериализуются там, где кодирование существует, и читаются как
пришли там, где его нет. Path-сегмент и query собраны из разделителей RFC
3986, поэтому значение с разделителем обязано быть экранировано, и RFC 6570
говорит как: оба percent-декодируются, а query — form-encoded content, поэтому
`+` это пробел. Cookie тоже декодируется: `$_COOKIE` декодирует любой SAPI.
**Значение заголовка читается дословно** — HTTP считает его непрозрачными
октетами, в реальном трафике его никто не экранирует, а декодирование
переписало бы значение, которое приложение получает нетронутым (`X-Path:
/a%20b` — это буквальный путь, `X-Discount: 50%` — не сломанный escape). Цена
названа явно: значение заголовка не может нести собственный разделитель стиля,
потому что экранировать его больше нечем.

### Схемы безопасности

```php
foreach ($contract->securitySchemes() as $name => $scheme) {
    // $scheme['type']: apiKey | http | mutualTLS | oauth2 | openIdConnect
    // apiKey: name, in — http: scheme, bearerFormat? — oauth2: flows —
    // openIdConnect: openIdConnectUrl
}
```

`components.securitySchemes` компилируется в immutable типизированную карту,
ключи которой — имена, на которые ссылаются требования `Operation::$security`;
потребителю не нужно перечитывать сырой документ, чтобы узнать, что `apiKey`
живёт в заголовке `X-Api-Key`. Каждая схема несёт `type` и ровно те поля,
которые определяет её тип: `apiKey` — `name`, `in` (`query`/`header`/`cookie`);
`http` — `scheme`, опционально `bearerFormat`; `oauth2` — `flows` с
объявленными потоками `implicit`/`password`/`clientCredentials`/
`authorizationCode`, у каждого свои URL и `scopes`; `openIdConnect` —
`openIdConnectUrl`; `mutualTLS` (только OpenAPI 3.1) — ничего больше.
Описания и расширения отбрасываются. Схема без поддерживаемого `type` или без
обязательного для типа поля fail-closed падает `InvalidContract` при
компиляции.

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
нарушений. Объявленный response-заголовок проверяется на присутствие, если он
`required`, а присутствующий заголовок со `schema` декодируется стилем
`simple` (`explode` как объявлено; у многозначных array/object-заголовков
необязательные пробелы вокруг запятых отбрасываются) и валидируется в
направлении ответа
(`response.header.schema`, `response.header.serialization`); Header Object в
форме `content` или с не-`simple` стилем fail-closed даёт
`response.header.unsupported`, объявление заголовка `Content-Type`
игнорируется, как требует спецификация, а объявление без схемы проверяет
только присутствие. `readOnly`/`writeOnly` применяются направленно. Root `security`
наследуется операциями, явный пустой список `security` делает операцию
анонимной, а получение credentials остаётся в пакете генераторов.

`validateResponse()` проверяет fixture ответа по identity операции без живого
request. Неизвестный ключ операции даёт одно структурированное нарушение
`response.operation.unknown`.

Request body с `application/x-www-form-urlencoded` декодируется по тем же
form-правилам, что и query-параметры, а свойство с объявленным в `encoding`
content type несёт вместо этого целый документ: JSON декодируется и
проверяется по схеме свойства, любой другой media type проверяется как строка,
которой он уже является. `multipart/form-data` поддерживает
ограниченный разбор частей, JSON и binary parts, повторяющиеся части-массивы и
`encoding` с content type и заголовками для свойства — объявленный заголовок
части обязан присутствовать при `required` и обязан удовлетворять своей схеме,
читается стилем `simple`, как заголовочный параметр запроса. Без content type в
`encoding` часть по умолчанию — `text/plain` для примитивов,
`application/octet-stream` для binary-строк, `application/json` для объектов,
а для массивов — умолчание типа элементов. Неподдержанные styles,
битые boundaries, повтор scalar parts и неверное содержимое parts отвергаются
fail-closed как `request.body.decode`.

Объявленный не-JSON media type с любой стороны (`text/plain`, `text/csv`,
`application/octet-stream`, ...) проверяется настолько, насколько позволяет
схема: без схемы body непрозрачно и проходит; со строковой схемой
(`type: string`, любой `format`, `minLength`/`maxLength`/`pattern`) сырое тело
проверяется как это строковое значение (`request.body.schema` /
`response.body.schema`); любая другая схема (например, XML-объект) не может
быть проверена по недекодированному телу и отвергается fail-closed как
`request.body.unsupported` / `response.body.unsupported`. Необъявленный media
type по-прежнему даёт `request.body.media_type` / `response.body.media_type`.

Ответ, который объявляет схему и приходит с пустым телом, даёт
`response.body.missing` — зеркало `request.body.missing`. Исключены статусы,
которые по определению не несут тела: `204`, `304` и любой ответ на запрос
`HEAD`, а также media type без схемы и безусловная булева схема.

При валидации body seekable PSR-7 stream читается с начала, после чего его
исходная позиция восстанавливается, в том числе при ошибке чтения. Если body
нужно проверить, но stream не поддерживает seek, validator не читает его и
возвращает `request.body.non_seekable` или `response.body.non_seekable`.
Body больше `Contract::MAX_MESSAGE_BODY_BYTES` (1 MiB) даёт соответствующее
нарушение `request.body.too_large` или `response.body.too_large`.
`ValidationResultFormatter` выводит все нарушения в стабильном порядке и
ограничивает поля, глубину, число элементов и expected/actual. Значение
печатается только там, где его имя можно проверить: body редактируется
целиком — имена его полей принадлежат приложению, а у нарушения по телу
instance path равен `$`; параметр печатается, но каждый член, чьё имя
совпадает с credential-паттерном (`authorization`, `api_key`, `token`,
`secret`, `password`, `cookie`), заменяется, а параметр, чьё собственное имя
совпадает, редактируется целиком. `ContractViolation` использует тот же
вывод.

## Безопасность

Неподдерживаемая семантика контракта никогда не игнорируется: версии,
диалекты, ссылки, стили сериализации и schema assertions вне support matrix
отвергаются fail-closed, а объявленное ограничение, которое пакет не может
вычислить, сообщается, а не пропускается. То, что вычислить можно, —
вычисляется: незнакомая форма схемы передаётся бэкенду, а не выбрасывается,
потому что молча перестать проверять часть контракта — единственная ошибка,
которую валидатор допускать не вправе. Пользовательские документы и тела
сообщений читаются с byte- и JSON-depth-бюджетами; expected/actual в
диагностике рендерятся в ограниченной форме без раскрытия
credential-параметров.

## Примеры

Runnable-скрипты — в [examples/](examples/README.md).

Компиляция схем кэшируется на экземпляр `Contract`: направленный rewrite,
JSON round trip и разбор бэкендом выполняются один раз на уникальную пару
«схема + направление + диалект», а не на каждое проверенное сообщение.
Контракт предъявляет одни и те же несколько схем на каждый запрос, поэтому
цена принадлежит именно сюда — разницу меряет `composer bench`.

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
