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
`UnsupportedSerialization`.

Каждое исключение пакета реализует `ContractException`, так что пакет можно
поймать одним типом: `InvalidContract` (и под ним `UnsupportedVersion`,
`UnsupportedSerialization`), `UnknownOperation` и `ContractViolation`. Базовые
классы остались прежними — `\InvalidArgumentException` и `\RuntimeException`,
поэтому существующие catch продолжают работать.

Заголовочный параметр с именем `Accept`, `Content-Type` или `Authorization`
игнорируется, как того требуют обе спеки: у этих трёх заголовков собственный
смысл в HTTP, а OpenAPI описывает их в других местах — согласование
представления через карту `content`, аутентификацию через схемы безопасности.
Под OAS 3.0 игнорируется и `requestBody` у `GET`, `HEAD` и `DELETE` — именно
это диалект велит делать потребителям; OAS 3.1 его разрешает, и он проверяется.

Каждый placeholder в path template обязан иметь
effective-параметр `in: path` с тем же именем и явным `required: true`; лишние
path-параметры отвергаются при компиляции контракта.

Объявления читаются строго, а не снисходительно. `requestBody`,
`parameters`, `content`, `encoding`, `headers` или Schema Object в форме,
которую пакет прочитать не может, — это `InvalidContract` на загрузке, а не
молча не проверяемая часть контракта, — и проверка доходит до каждой вложенной
схемы, поэтому нечитаемый `items` или член `properties` отвергается там, где
записан, а не на первом сообщении, которое до него дойдёт; булево поле,
записанное строкой
(`required: "true"`), отвергается, а не откатывается к значению по умолчанию;
схема со значением, которое JSON закодировать не может (`.nan` и `.inf` в
YAML), отвергается до того, как дойдёт до backend'а валидации; документ, чьи
`paths` не дают ни одной операции, отвергается, а не компилируется в
контракт, отвечающий `UnknownOperation` на любой запрос; а YAML-файл, который
не парсится, сообщается как `InvalidContract`, а не собственным типом
исключения парсера.

Соседи `$ref` читаются по диалекту, который объявил документ. В 3.0 они
игнорируются везде — спека говорит, что добавленные свойства Reference Object
«SHALL be ignored», а Schema Object в 3.0 содержит Reference Object, а не
схему 2020-12. В 3.1 Reference Object сохраняет только `summary` и
`description` (они перекрывают одноимённые поля цели), а соседи Schema Object
применяются *дополнительно* к тому, что приносит ссылка, как требует 2020-12:
`{$ref: Count, maximum: 10}` утверждает и `Count`, и maximum, и компилируется
в соответствующий `allOf`.

`fromFile()` дополнительно разрешает относительные `$ref` на соседние
JSON/YAML файлы. Каждый referenced-файл обязан оставаться внутри дерева
каталога entry-файла: абсолютные пути, URI-схемы, percent-encoded пути,
traversal и symlink escape отклоняются до какого-либо чтения, а ошибки
резолюции показывают пути относительно document root. У `fromArray()` и
`fromJson()` нет доверенного filesystem root — они принимают только
same-document ссылки. Документы ограничены бюджетами: размер в байтах, JSON
depth, глубина `$ref`, общий node budget, а для многофайловых документов —
общие на весь граф бюджеты числа файлов и байтов.

#### Бюджеты

`Limits` несёт бюджеты, которые задаёт вызывающая сторона; его принимает
каждая фабрика:

```php
use Rasuvaeff\OpenApiContract\Limits;

$contract = Contract::fromFile('openapi.yaml', new Limits(
    documentBytes: 40 * 1024 * 1024,   // по умолчанию 10 MiB
    messageBodyBytes: 8 * 1024 * 1024, // по умолчанию 1 MiB
    documentFiles: 256,                // по умолчанию 64
));
```

Бюджет — это политика, а не вердикт. Тело больше `messageBodyBytes` даёт
`request.body.too_large` / `response.body.too_large`, и этот код означает, что
валидатор отказался читать тело, — а не что сообщение признано неверным. Гейт,
отвергающий по `isValid()`, иначе отверг бы трафик, который никто не проверял:
приложению с законно большими телами следует поднять бюджет, а не читать это
нарушение как отказ. Дефолты малы намеренно — неограниченное чтение внутри
middleware это denial of service. Бюджет меньше 1 отвергается
`\InvalidArgumentException`.

### Операции и matching

```php
foreach ($contract->operations() as $operation) {
    // Operation: key, operationId, method, path, parameters, requestBody,
    // responses, serverBases, security, servers
}

$matched = $contract->match($request);        // MatchedOperation|null
$matched = $contract->requireMatch($request); // бросает UnknownOperation
$operation = $contract->operation('pets.get'); // бросает UnknownOperation

// Response Object, в который резолвится статус — точный код, затем диапазон
// NXX, затем `default`, — ровно так же, как его выбирает валидация ответа;
// null, если статус не объявлен или вовсе не является HTTP-статусом.
$declared = $operation->responseFor(404); // ['key' => '4XX', 'definition' => [...]] | null
```

Identity операции — `operationId`, если он есть, иначе стабильный
`METHOD /path`. `Operation` — это read model: контракт собирается компиляцией
документа, а конструктор помечен `@internal` — ни один публичный путь не
валидирует собранную руками операцию, и шейпы, которые конструктор принимает,
это выход компилятора, а не проверяемый вход. Шейп `CompiledParameter`,
который импортирует потребитель, для него доступен на чтение, и минорный
релиз может добавить в него ключи. Скомпилированные параметры несут
`allowReserved` именно для таких потребителей: валидация его не читает, потому
что значение, оставившее reserved-символ незакодированным, неотличимо от
разделителя, на который оно похоже, — пакет читает такой query ровно так же,
как его прочитает SAPI, — а потребитель, который рендерит значение query, не
выведет его из схемы и без него не решит, кодировать ли reserved-символы. Параметры Path Item и Operation
сливаются по паре «location + name», и объявление операции заменяет
объявление Path Item для той же пары, как требует спека; одна и та же пара,
объявленная дважды *внутри* одного списка, отвергается: параметр уникален по
имени и расположению, а прочитать любое из двух объявлений значит молча
потерять другое. Имена заголовков сравниваются без учёта регистра, поэтому
`X-Trace` и `x-trace` — один параметр. Скомпилированные параметры сохраняют объявленные
`example`/`examples` как аннотации: валидация их игнорирует, а пакет
генераторов использует в детерминированной example-фазе. Example Object,
до которого ведёт `$ref`, разрешается; то, что лежит *внутри* примера, — это
данные, и они сохраняются ровно как написаны, включая члены, похожие на
`$ref`; так же с `default`/`const`/`enum` схемы и с любыми расширениями. `MatchedOperation` несёт операцию и сырые path-параметры из
URI. Matching учитывает server base paths, предпочитает конкретные пути
шаблонным, декодирует каждый сегмент ровно один раз и отвергает
декодированные разделители, выводящие значение из template slot. Завершающий слэш — часть пути: `/pets` и `/pets/` это разные ресурсы, как их
и различает RFC 3986. Placeholder
может делить сегмент с литералами (`/report.{format}`, `/v{version}/items`,
`/{a}-{b}`); литеральные части сопоставляются так, как записаны.

Servers компилируются в полную модель (`Operation::$servers`): scheme, host,
port и base path с precedence operation > path > root и подстановкой
defaults у server variables. Absolute server ограничивает каждый компонент
URI, который запрос реально несёт, — нормализованные scheme, host и
effective port (`443` для `https`, `80` для `http`), поэтому одинаковый path
на двух hosts выбирает только правильную операцию; relative server и
path-only request URI остаются host-agnostic — запрос без authority
матчится только по пути и осознанно не отвергается за то, что не назвал host,
на который и не претендовал. Необъявленные переменные,
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

Имя параметра, встретившееся больше одного раза там, где style допускает одно
значение, — это нарушение, а не значение. `?n=5&n=999` — корректно
сформированный query, смысл которого зависит от рантайма: PHP берёт последнее
вхождение, Go — первое, Node — оба. Прочитать любое из них означало бы
позволить запросу удовлетворить контракт одним значением и отдать приложению
другое. Развёрнутый список это не затрагивает: повтор имени там и есть смысл
стиля.

Карта `content` матчится по специфичности, а не по порядку, в котором записаны
её ключи: точный `type/subtype` выигрывает у `type/*+suffix`, тот — у
`type/*`, а тот — у `*/*`; порядком объявления решаются только равные по
специфичности ключи. Wildcard, записанный выше точного media type, значит то
же самое, что записанный ниже него.

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
Body больше настроенного `messageBodyBytes` (по умолчанию 1 MiB) даёт
соответствующее нарушение `request.body.too_large` или
`response.body.too_large` — оно говорит, что тело не читали, а не что оно
неверно.
`ValidationResultFormatter` выводит все нарушения в стабильном порядке и
ограничивает поля, глубину, число элементов и expected/actual. Значение
печатается только там, где его имя можно проверить: body редактируется
целиком — имена его полей принадлежат приложению, а у нарушения по телу
instance path равен `$`; параметр печатается, но каждый член, чьё имя
совпадает с credential-паттерном (`authorization`, `api_key`, `token`,
`secret`, `password`, `cookie`), заменяется, а параметр, чьё собственное имя
совпадает, редактируется целиком. `ContractViolation` использует тот же
вывод.

### Коды нарушений

Полный набор. Код — стабильный идентификатор, по которому можно ветвиться;
текст сообщения, который рядом рендерит `ValidationResultFormatter`, —
диагностика, и он может быть переформулирован в любом релизе, поэтому пинить
надо коды, а не текст.

| Код | Когда |
|---|---|
| `request.operation.unknown` | запросу не соответствует ни одна операция |
| `request.server.mismatch` | путь совпал, но ни один объявленный server — нет |
| `request.parameter.missing` | отсутствует `required`-параметр |
| `request.parameter.duplicate` | имя несёт больше одного значения там, где style допускает одно |
| `request.parameter.serialization` | значение параметра не разбирается в своём style |
| `request.parameter.schema` | значение параметра не удовлетворяет схеме |
| `request.body.missing` | `required`-тело пустое |
| `request.body.media_type` | media type тела не объявлен (или у тела нет годного content) |
| `request.body.json` | JSON-тело не парсится |
| `request.body.decode` | form- или multipart-тело не декодируется как объявлено |
| `request.body.schema` | тело не удовлетворяет схеме |
| `request.body.unsupported` | не-JSON и не-form media type несёт схему, которую нельзя проверить на недекодированном payload |
| `request.body.too_large` | тело больше настроенного `messageBodyBytes`, поэтому не читалось |
| `request.body.non_seekable` | поток тела нельзя перемотать, поэтому он не вычитывается |
| `request.body.unreadable` | поток тела сообщает, что не закончился, и читает пусто |
| `response.operation.unknown` | `validateResponse()` получил ключ операции, которого нет в контракте |
| `response.status.invalid` | статус не является HTTP-статусом (вне 100-599) |
| `response.status.mismatch` | статус корректен, но операция не объявляет для него ответа |
| `response.header.missing` | отсутствует `required`-заголовок ответа |
| `response.header.serialization` | значение заголовка ответа не разбирается |
| `response.header.schema` | значение заголовка ответа не удовлетворяет схеме |
| `response.header.unsupported` | Header Object использует `content` или style, отличный от `simple` |
| `response.body.missing` | ответ, объявивший схему, не прислал тела |
| `response.body.media_type` | media type ответа не объявлен |
| `response.body.json` | JSON-тело ответа не парсится |
| `response.body.schema` | тело ответа не удовлетворяет схеме |
| `response.body.unsupported` | то же, что `request.body.unsupported`, на стороне ответа |
| `response.body.too_large` | тело ответа больше настроенного `messageBodyBytes`, поэтому не читалось |
| `response.body.non_seekable` | поток тела ответа нельзя перемотать |
| `response.body.unreadable` | поток тела ответа сообщает, что не закончился, и читает пусто |


Где заканчивается строгость — решение осознанное: пакет проверяет то, от чего
зависит вердикт по сообщению, и не проверяет то, что влияет только на
документацию. Отсутствующий `url` у сервера, схема безопасности без
обязательных для её типа полей, операция без `responses` — отвергаются, потому
что без них валидация не состоится. Response Object без обязательного по спеке
`description` или `encoding`, объявленный у media type, к которому спека его не
применяет, — принимаются, потому что вердикт от них не зависит. Для остального
есть линтер.

## Безопасность

**Объявленный `security` не проверяется.** Требования компилируются, и
требование, ссылающееся на необъявленную схему, роняет документ, — но запрос
без API-ключа проходит валидацию чисто. Пакет проверяет форму обмена по
контракту, а не авторизацию вызывающего: подстановка учётных данных — это
`rasuvaeff/property-testing-openapi`, а их проверка — middleware приложения.

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

Ключевое слово `pattern` — это регулярное выражение из документа, и backend
валидации исполняет его через `preg_match`. Контракт — доверенный вход (это
ваш документ, а не ваш трафик), но если вы компилируете чужие документы,
учтите: катастрофически бэктрекающий паттерн выбирает их автор. PHP-шный
`pcre.backtrack_limit` ограничивает каждый матч, и упёршийся в лимит матч
отваливается fail-closed, а не висит.

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
