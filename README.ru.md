# rasuvaeff/openapi-contract

Framework-neutral валидация PSR-7 request/response exchanges по контрактам
OpenAPI.

> Статус: идёт feasibility-работа над контрактом 0.1. Стабильного публичного
> API пока нет, выпускать пакет в текущем состоянии нельзя.

## Область

Пакет будет загружать документы OpenAPI 3.0 и 3.1, сопоставлять PSR-7 запросы
с операциями и валидировать обе стороны exchange. Неподдерживаемые версии,
диалекты, ссылки и стили сериализации отвергаются fail-closed.

OpenAPI 3.2, remote- и file-ссылки, XML, multipart, form-urlencoded и binary
body не входят в первый релиз.

## Разработка

Текущая веха сравнивает JSON Schema backend'ы на исполняемом корпусе OAS
3.0/3.1 и фиксирует семантику выбора response. Установка и полный gate через
Docker:

```bash
make install
make build
```

В тестах property-based проверки покрывают законы и serialization round-trip,
а doubles Understudy Testo — наблюдаемые взаимодействия на PSR-границах.

Текущий статус примеров описан в [examples/README.md](examples/README.md).

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
