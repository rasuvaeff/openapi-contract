# rasuvaeff/openapi-contract

Framework-neutral валидация PSR-7 request/response exchanges по контрактам
OpenAPI.

> Статус: реализация продолжается. Начальный публичный API контракта доступен
> для pre-release использования; генераторы и дополнительные проверки ещё
> расширяются.

## Область

Пакет загружает документы OpenAPI 3.0 и 3.1, сопоставляет PSR-7 запросы
с операциями и валидировать обе стороны exchange. Неподдерживаемые версии,
диалекты, ссылки и стили сериализации отвергаются fail-closed.

OpenAPI 3.2, remote- и file-ссылки, XML, multipart, form-urlencoded и binary
body не входят в первый релиз.

## Разработка

Текущий срез предоставляет `Contract::fromArray()`, `fromJson()`, `fromFile()`,
сопоставление операций, валидацию request, выбор response и проверку JSON body.
Установка и полный gate через Docker:

```bash
make install
make build
```

В тестах property-based проверки покрывают законы и serialization round-trip.

Текущий статус примеров описан в [examples/README.md](examples/README.md).
Решение по backend и статус исполняемого корпуса описаны в
[FEASIBILITY.md](FEASIBILITY.md).

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
