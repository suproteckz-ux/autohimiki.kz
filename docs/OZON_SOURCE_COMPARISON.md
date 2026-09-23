# Сравнение и перенос Ozon из автохимия.kz

Дата: 23.09.2026. Только локальные изменения autohimiki.kz; источник читался без
изменений, запуска artisan, миграций, тестов или API. Production не использовался.

## 1. Где найден источник

В зарегистрированном `C:/Users/anton/OneDrive/Documents/autohimiya-kz/laravel`
Ozon-кода нет: проверены текущая ветка redesign-v1, main и имена файлов в доступной
Git-истории. Нужная реализация найдена в соседнем проекте:

`C:/Users/anton/OneDrive/Documents/autohimiya-laravel`

HEAD источника: `a36309f0d1974f985b08eef186237b621d51d9c3`.
Посторонние незакоммиченные Kaspi/storage-audit файлы источника не менялись.
Наличие рабочего кода и регрессионных тестов подтверждено; текущие успешные
live-ответы Seller API не проверялись. Нельзя выдавать HTTP fake за production-проверку.

## 2. Изученные файлы и история

Пути относительно источника:

- `app/Services/Ozon/OzonApiClient.php`, `OzonConnectionService.php`, `OzonWarehouseService.php`.
- `OzonProductExportService.php`, `OzonProductPayloadBuilder.php`, `OzonAnnotationAttributeResolver.php`.
- `OzonProductPreparationService.php`, `OzonProductValidationService.php`, `OzonTaxonomyService.php`.
- `OzonPriceCalculator.php`, `OzonStockCalculator.php`, `OzonDescriptionBuilder.php`, `OzonImageService.php`.
- `app/Models/OzonAccount.php`, `app/Enums/OzonProductStatus.php`.
- `app/Services/Automation/Handlers/OzonConnectionCheckHandler.php`,
  `OzonWarehouseSyncHandler.php`, `OzonProductExportHandler.php`.
- `tests/Feature/Ozon/OzonReadOnlyApiTest.php`, `OzonSingleProductExportTest.php`.
- `database/migrations/2026_08_06_000003_create_ozon_products_table.php`;
  проверены имена и определения остальных Ozon-таблиц в migrations.
- `docs/OZON_DATABASE_PREVENTION_HOTFIX_REPORT.md`.

История подтверждает исправления:

- `6a9543c`: устаревший `/v1/warehouse/list` заменён seller info и warehouse v2;
  offset/result заменены cursor/warehouses.
- `413e96e`: seller info получает JSON-объект `{}`, а не массив `[]`;
  тест воспроизводит `proto: syntax error ... unexpected token [`.
- `6914e78`: on-demand определение «Аннотации» вместо предположения об ID.
- `826bb9a`: снято ограничение экспорта только ER5.
- `2090b53`: остановлено разрастание taxonomy payloads/словарей и логов.
  Отчёт источника описывает около 176 тыс. operations и 270 тыс. attributes за день;
  это сведения отчёта, не повторное измерение нами production-БД.

## 3. Подтверждённые кодом endpoints

| Назначение | Endpoint / контракт в источнике | Решение в autohimiki.kz |
|---|---|---|
| Connection / seller | POST `/v1/seller/info`, body `{}` | Перенесено |
| Склады | POST `/v2/warehouse/list`, limit/cursor; warehouses/has_next/cursor | Перенесено, одна явно запрошенная страница |
| Создание | POST `/v3/product/import`, items; result.task_id или task_id | Сохранено, добавлен fallback task_id |
| Результат импорта | POST `/v1/product/import/info`, task_id | Сохранено |
| Аннотация выбранного типа | POST `/v1/description-category/attribute`, category/type/language | Перенесено без taxonomy-таблиц |
| Полное дерево | POST `/v1/description-category/tree` | Запрещено; не переносилось |
| Словари | `/v1/description-category/attribute/values` в allow-list; после hotfix массовый вызов убран | Не переносилось |
| Product list | В Ozon-клиенте источника не найден | Наш точечный `/v3/product/list` сохранён для защиты от дубликатов |
| Stock / price API | В write allow-list источника нет; тест явно исключает stocks/prices | Наш отдельный stock sync сохранён, price API отсутствует |

Нельзя утверждать, что stock API или product/list «перенесён из старого рабочего
проекта»: их реализации там не найдено.

## 4. Авторизация и конфигурация

Источник: `https://api-seller.ozon.ru`, `Client-Id`, `Api-Key`, JSON/Accept JSON.
Ключ лежит в `ozon_accounts`, cast `encrypted`, hidden `api_key`, а не в Ozon `.env`
переменных. Источник имеет общую automation-систему и Filament действия; отдельных
connection/export Artisan-команд с нашими именами нет. Из Ozon CLI найдены
`ozon:operations-prune`, `ozon:emergency-storage-cleanup`, их не переносили.

В autohimiki.kz остаётся `config/ozon.php` и существующие OZON_CLIENT_ID/API_KEY,
ENABLED/CURRENCY/VAT/WAREHOUSE_ID. Секреты из исходной БД/`.env` не копировались.
Новых account, operation, warehouse или taxonomy таблиц не добавлено.

## 5. Склады

Источник перебирал страницы warehouse v2 и сохранял `ozon_warehouses`.
Поддерживал status строкой или объектом с state, is_archived и ручной default.
Теперь `ozon:warehouses` использует тот же HTTP-контракт и разбор статуса,
но только печатает одну страницу. `--cursor` запрашивает явно выбранную следующую.
Warehouse ID не выбирается автоматически и не записывается в конфиг.

## 6. Создание, ручная доработка и цена

Источник сначала сохраняет локальный snapshot `ozon_products` со статусом
draft/ready, затем отправляет один товар обычным import. Нет draft API или
доказательства автоматического появления любой неполной карточки в кабинете.
После ответа сохраняется processing/task ID; отдельная проверка читает item errors.
Stock API при создании не вызывается. Автоматической публикации в изученном коде нет.

Source payload: SKU, prepared name/images, KZT, рассчитанная цена, vat=0,
category/type, attributes, complex_attributes, измерения mm/g, опциональный ТН ВЭД.
Наши бизнес-правила сохранены: текущая Product.price без коэффициентов;
VAT из конфига без догадок; ТН ВЭД/regulatory не добавляются; фото и контент только сайта.
Перенесён формат аннотации и измерений, но только для существующих данных.
Нули вместо отсутствующих размеров, примерные размеры, множители и defaults
из старого проекта не переносятся.

Защита источника — task ID и pending/running/completed operation; failed допускает
повтор, HTTP write мог повторяться при 429/5xx/timeout. Она слабее текущего запрета
повторной цены после неоднозначной отправки. Поэтому сохранены наши уникальная
связь до HTTP, поиск удалённого SKU и отсутствие автоматического повторного import.
Смена SKU блокируется. Stock после явной ручной публикации отправляет только
max(0, Product.quantity), без цены; лимит остатков из старого калькулятора не перенесён.

## 7. Категория/type и taxonomy

В источнике есть и ручной fallback category/type, и полная taxonomy с рекурсивным
сохранением дерева, и массовые attributes. Ни один из этих массовых механизмов
не переносился. Существующая `ozon_category_mappings` не изменена.

Для одного известного mapping читаются только attributes этой пары. Из ответа
используется только ID текстовой «Аннотации» / «Описание товара» без словаря/complex.
Он кешируется в памяти текущего клиента по аккаунту/category/type; другие атрибуты,
сырой ответ и словари в БД не записываются. Если ID не найден — этот SKU не импортируется.
Dry-run не вызывает API, поэтому ID в нём помечен как ещё не проверенный.

## 8. Что было неверно / почему API unavailable

Наша проверка соединения использовала product/list и ожидала result.items,
хотя найденная рабочая схема проверяет seller/info. Все 429/5xx/network/неверные
схемы ответа сводились к одной строке API unavailable, теряя причину.
Жёсткий ID описания 4191 противоречит on-demand resolver и тесту источника,
который явно проверяет другой ID. Оба места исправлены по исходникам.

**Точная причина конкретного production API unavailable не установлена**:
старый вывод не содержит HTTP-статуса, а мы не обращались к production или API.
Смена endpoint сама по себе не доказывает, что прежний product/list не работает.
Warnings pdo_oci/PDO/mysqlnd не объявляются причиной API-ошибки без HTTP-данных.
Новая диагностика отдельно показывает 401/403/429/5xx, timeout, network,
ошибки JSON/схемы, HTTP 200 business error, obsolete method и proto syntax error.
Сырые ответы, request headers, ключи и тексты исключений не логируются/не печатаются.

## 9. Изменения текущего проекта

- `app/Services/Ozon/OzonClient.php` — seller/warehouse diagnostics, точечная аннотация,
  HTTP business error, безопасные сообщения и вывод выбранных полей.
- `app/Services/Ozon/OzonPayload.php` — проверенный ID аннотации, dictionary_value_id=0,
  complex_attributes; измерения только из явно заданных unit-labelled attributes.
- `app/Services/Ozon/OzonExporter.php` — resolver до попытки import, fallback task_id.
- `app/Console/Commands/OzonExportCategory.php` — dry-run валидирует локальные данные
  без угадывания ID аннотации и сообщает об этом ограничении.
- Новые `OzonSellerInfo.php`, `OzonWarehouses.php`; команда `ozon:test-connection`
  использует изменённый клиент, её файл не потребовал изменения.
- `tests/Feature/OzonConnectionTest.php`, `OzonExportTest.php`.
- `docs/OZON_EXPORT.md` и этот отчёт.

Миграции, модели, `.env`, config и существующие storefront/Kaspi/Paloma не изменены.
Filament, вторую интеграцию, очереди taxonomy не добавляли.

## 10. Проверки

- Ozon: **52 tests, 482 assertions — OK**.
- Полный `php artisan test`: **349 passed, 7015 assertions — OK**.
- Laravel Pint и `git diff --check`: без ошибок.
- Проверены seller info с точным `{}`, 401/403/429/500/502/503, timeout/network,
  ошибки JSON/business/schema, warehouse v2 с явным cursor, редактирование секретов,
  mapping и единичный SKU, точечная аннотация/отсутствие guessed ID, кеш только
  одной пары, запрет дерева/словарей, цена только create, stock без цены,
  dry-run без HTTP/записи, отсутствие повторного создания и продолжение batch после ошибки.
- Все HTTP-ответы в этих проверках — fake. Миграции — только тестовая SQLite :memory:.

## 11. Следующий разрешённый тест

```bash
php artisan ozon:test-connection
php artisan ozon:seller-info
php artisan ozon:warehouses
php artisan ozon:export-category --category=<actual-slug> --sku=<SKU> --dry-run
```

Эти команды здесь приведены как следующий порядок, реальные HTTP-запросы не запускались.
Для первой отправки ещё нужны фактическая локальная категория, подтверждённая
пара category/type, один SKU с проверенными фото/данными, успешный seller check
и проверка обязательных данных выбранного типа. Размеры/вес могут потребоваться API:
мы не подменяем их нулями, если их нет. Публикация/ручная доработка проверяется
в кабинете после отдельно разрешённого одиночного import, не массовой выгрузки.
Положительный остаток допускается только после ручного подтверждения публикации.

Один SKU **ещё не признан готовым к реальному тесту**: реальные данные/API не
проверены. Commit/push, deploy, production migration, export и stock sync не выполнялись.

**ПОЛНОЕ ДЕРЕВО КАТЕГОРИЙ OZON НЕ СКАЧИВАЕТСЯ.**
