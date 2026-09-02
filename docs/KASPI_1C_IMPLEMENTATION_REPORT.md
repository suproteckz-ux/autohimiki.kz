# KASPI-1C IMPLEMENTATION REPORT

Проект: `C:\Users\anton\OneDrive\Documents\au\autohimiki.kz`.
Разрешён только exact string SKU `00000000680`.
Реализация локальная, не закоммичена. Commit/amend/push/PR/merge/deploy/live import не выполнялись. Production не изменён. `.env`, 1C и зависимости не изменялись; миграций нет.

## Reference reused

Из `C:\Users\anton\OneDrive\Documents\autohimiya-laravel` изучены collector PHP/Node, `KaspiEnrichmentParser`, bridge, import controller/service, payload validator, secure downloader и draft publisher.

Перенесены по смыслу: последовательность candidate → resolver → collector → parser → validation → import; balanced JSON extraction `BACKEND.components.item`; `primaryImage`/`galleryImages`, нормализация gallery-size URL; description и specifications/featureValues; ограниченный download и cleanup.

Адаптация к target: нет reference drafts/receipts/attributes relation или новых таблиц. Reference publisher не копировался: он допускает force-photo удаление и имеет другую модель. Исправлены опасные для этого этапа допущения: CAPTCHA при status=ok теперь ошибка; main image проверяется на public disk; redirect изображений запрещён; IP разрешается один раз и закрепляется через cURL. Нет исправления испорченных символов подстановкой выдуманных единиц измерения.

## API endpoint

`POST /api/internal/kaspi-content/import` — запись.
`GET /api/internal/kaspi-content/import?sku=00000000680` — read-only preview физического main image, description presence/length и gallery count. GET не скачивает изображения и не пишет Product.

Оба используют существующий `KASPI_INTERNAL_API_TOKEN`, HTTPS в production, лимит 6 запросов/минуту/IP. Ответы private/no-store. POST требует JSON version 1, максимум 128 KiB, существующий active exact SKU и ожидаемый storefront slug. Неизвестные поля отклоняются на каждом уровне схемы. Исходный JSON проверяется до влияния TrimStrings на SKU. Payload не содержит attributes: они остаются только локальной диагностикой parser.

Schema: `version`, `sku`, `storefront_url`, `kaspi_url`, `source` (`collector`, `resolver_verified`, `merchant_id`, `city_id`, `captcha`), `content` (`title`, `description`, `images` URL list).

## Local command / guard

`kaspi:push-production --sku=00000000680 --debug`

Отсутствующий/другой SKU: `sku_not_allowed_for_kaspi_1c`, до HTTP/Node. Browser доступен только Windows + CLI + APP_ENV=local + KASPI_LOCAL_BROWSER_ENABLED=true. Используются существующие config services.kaspi.*. Node получает только URL и headless=false; resolver также получает публичные SKU/merchant/city. Наследование секретов окружения блокируется существующим environment allow-list runner.

Команда получает exact candidate, проверяет ожидаемый storefront, вызывает прежний widget resolver с merchant/city, требует ожидаемый resolved URL, собирает страницу видимым Chromium, парсит и валидирует. Перед POST показывает SKU, URL, title, description length, image/attribute count, фактический main image plan и description plan из GET preview.

Gallery additions planned показывается честным диапазоном 0..N: точное количество можно узнать только после server-side download/hash dedupe. POST возвращает фактический gallery_added.

`--dry-run` выполняет resolve/collect/parse/validate и read-only GET preview, но никогда POST.

Collector: два ограниченных прохода для медленной загрузки, навигация 30 секунд/попытка, ожидание product content 15 секунд, subprocess 150 секунд. CAPTCHA, неверный URL/product ID, отсутствующий title/images, пустой/слишком большой HTML и неуспешный HTTP завершаются failure с reason. Raw HTML и browser stderr не печатаются и не сохраняются в БД.

## Parsed fields / content protection

Parser возвращает URL, title, description, attributes, gallery URLs и backend_item_found. BACKEND первичен; fallback — product JSON-LD/meta/ограниченный DOM товара. JSON-LD другого product URL и обычные DOM recommendation images исключены. Offers/price/availability не извлекаются в результат.

- Name/title существующего Product не меняется.
- Description: непустое существующее сохраняется; пустое заполняется только непустым очищенным Kaspi description. HTML проходит whitelist без атрибутов/скриптов/встроенных объектов.
- Attributes: diagnostic_only, существующее JSON-поле не меняется.
- Обновляются только description при разрешённых условиях, main_image и при необходимости main_image_webp. Gallery добавляется в product_images.
- Используется query builder, обходящий observers и автоматическое обновление Product timestamps.

Production description сейчас: NOT VERIFIED. Публичный read-only GET из текущего окружения не удался; authenticated production requests не выполнялись. Поэтому фактический live результат updated/preserved не заявляется. До будущего POST GET preview покажет состояние; POST вернёт description=updated/preserved и description_reason=existing_empty/existing_nonempty/collected_empty.

## Media policy / broken image detection

Путь проверяется через public disk exists, а не только DB nonempty. Пустой или физически отсутствующий main разрешено заменить первым успешно проверенным изображением Kaspi. Существующий файл main сохраняется. Это проверка отсутствующего файла, не полный аудит повреждений/HTTP-доступности медиа.

Все уникальные Kaspi изображения добавляются в gallery; первый также может стать main. Ручные файлы, строки gallery и alt не удаляются/не перезаписываются. Dedupe: одинаковые входные URL отклоняются валидатором, parser нормализует размерные варианты, importer сравнивает SHA-256 существующих main/gallery файлов и новых байтов.

Новые пути: `storage/app/public/products/kaspi/00000000680/<sha256>.<jpg|png|webp>`.

WebP отдельно не генерируется. Новый path_webp = null. При замене main main_image_webp = null; у сохранённого main только несуществующая derivative-ссылка очищается. Существующие файлы WebP не удаляются.

## Image download policy / idempotency

Только HTTPS `resources.cdn-kaspi.kz` с product image paths `/img/m/p/` или `/shop/medias/`; без userinfo/port/fragment/traversal. Публичный IPv4 DNS проверяется и закрепляется CURLOPT_RESOLVE, proxy отключён, redirect запрещён. Connect timeout 5 секунд, timeout 15 секунд/изображение, максимум 12 изображений и 5 MiB/файл. Ограничение проверяется по Content-Length, download progress и фактическим байтам. MIME определяется fileinfo + getimagesize: JPEG/PNG/WebP, максимум 40 миллионов пикселей. HTML/CAPTCHA вместо изображения отклоняется.

Product row lock + cache lock на один SKU, DB transaction, cleanup только созданных этой попыткой файлов при исключении. Повтор тех же байтов даёт прежние deterministic paths, не дублирует gallery и сохраняет description. Receipt ledger/миграции не нужны. Если удалённый URL со временем отдаёт другие байты, это новое изображение, а не тот же контент.

## Verification

- KASPI-1C PHP: **21 tests / 190 assertions**, passed.
- Full PHP: **88 tests / 724 assertions**, zero failures/errors, **1 known risky**: `PublicPagesSmokeTest::test_seo_page_and_filter_pages_load`, line 270, unclosed output buffer. Тест не изменялся.
- Node: **6 tests passed** (5 existing resolver + 1 collector test with multiple cases), без запуска Chromium.
- Pint: **passed**, все 15 затронутых PHP-файлов.
- `git diff --check`: passed; новые файлы дополнительно проверены без staging.

Покрытие: auth/HTTPS/rate limit; unknown/inactive/forbidden/raw exact SKU; malformed/unknown/commercial/nested fields; URL/merchant/city/CAPTCHA proof; весь исходный Product кроме разрешённых полей; broken-main replacement/manual preservation; hash dedupe/repeat; download/DB failure rollback и cleanup; oversize/redirect/HTML MIME; WebP null; local guard, missing SKU, BACKEND fixture, JSON-LD fallback; full fake bridge GET→Node→preview→POST и dry-run без POST. В тестах HTTP и Node поддельные: реальный live импорт и CDN download не выполнялись.

## Exact changed-file manifest — 18 files

Modified (3):

```text
app/Providers/AppServiceProvider.php
app/Services/Kaspi/KaspiLocalNodeProcessRunner.php
routes/api.php
```

New (15):

```text
app/Console/Commands/KaspiPushProductionCommand.php
app/Http/Controllers/InternalKaspiContentImportController.php
app/Services/Kaspi/KaspiEnrichmentParser.php
app/Services/Kaspi/KaspiLocalPageCollector.php
app/Services/Kaspi/KaspiProductionBridgeService.php
app/Services/Kaspi/KaspiProductionImportService.php
app/Services/Kaspi/KaspiProductionPayloadValidator.php
app/Services/Kaspi/KaspiSecureImageDownloader.php
app/Services/Kaspi/KaspiSingleProductPolicy.php
scripts/kaspi-product-page-collector.mjs
scripts/kaspi-product-page-collector.test.mjs
tests/Feature/KaspiContentBridgeTest.php
tests/Feature/KaspiContentLocalTest.php
tests/Feature/KaspiImportApiTest.php
docs/KASPI_1C_IMPLEMENTATION_REPORT.md
```

Прочие исходные untracked audit-документы и пользовательский текстовый файл не менялись и не входят в manifest.

## FIRST LIVE COMMAND

Только после отдельного подтверждения и развёртывания KASPI-1C endpoint. Сейчас команда НЕ запускалась.

```powershell
Set-Location 'C:\Users\anton\OneDrive\Documents\au\autohimiki.kz'
& 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' artisan kaspi:push-production --sku=00000000680 --debug
```

Для предварительного прохода без POST: та же команда с `--dry-run` (тоже запускает локальный видимый Chromium).

## EXPECTED RESULT

При отсутствии файла текущего main — первый валидный Kaspi image становится main, видимым на storefront. Недостающие изображения сохраняются на public disk и в gallery. Description либо preserved (существовало), либо updated (было пустым и Kaspi description получено). Повтор не добавляет дубли.

## MUST REMAIN UNCHANGED

Price/old_price, quantity, in_stock, sku, slug, name, category_id/brand_id, SEO/meta/h1/canonical, active/publication/flags, attributes, short_description, usage_instructions, прочие неразрешённые Product поля и timestamps, все 1C поля/ledger/scheduler; существующие ручные изображения и gallery metadata.

## RISKS / NOTES

- Live состояние/работоспособность новой связки ещё не проверены; endpoint не развёрнут этой задачей.
- Source resolver proof — данные от доверенного локального клиента с Bearer token; сервер не запускает браузер и не перепроверяет widget через Chromium.
- Нужны серверные PHP cURL/fileinfo, работающий public disk/storage link и доступ CDN. При недоступности/redirect/другом image host импорт завершится ошибкой без частичного DB update.
- Скачивание синхронное (до 12 × 15 секунд); ограничения времени запроса на сервере могут прервать его. При неопределённом исходе POST клиент не делает автоматический retry и сообщает check_before_retry.
- DB rollback и cleanup покрывают обычные исключения. Жёсткое завершение PHP/сервера может оставить orphan hash-файл; повтор использует его безопасно, глобальная очистка не реализована.
- Существующая storefront view выводит main image, но не строит gallery carousel. Gallery хранится в БД/storage; UI галереи этим этапом не менялся.
- Полный suite имеет указанный существующий risky SEO test.
