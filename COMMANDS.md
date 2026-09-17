# Команды для autohimiki.kz на hoster.kz (Plesk + CloudLinux)

## Kaspi: одноразовое полное обновление контента — только локальный Windows

Это отдельный одноразовый режим существующего `kaspi:push-production`, а не новая
pipeline. Resolver и parser работают **только локально на Windows**. Playwright на
production запрещён. Сервер принимает JSON через существующий HTTPS internal API
и скачивает изображения существующим защищённым downloader.

**Dry-run (без POST импорта, изменений товаров, медиа и инвалидации кэшей):**

```powershell
php artisan kaspi:push-production --all --force-content-refresh --dry-run
```

Проверить JSON-строки по каждому товару, причины пропусков и итоговый `approval_hash`.
Затем, только после проверки результатов, выполнить с тем же scope:

```powershell
php artisan kaspi:push-production --all --force-content-refresh --approve=HASH
```

`HASH` — 64-символьный SHA-256 из dry-run. Без `--approve` выполнение force запрещено.
`--approve` недопустим в normal-режиме и вместе с `--dry-run`. Ровно один scope обязателен:

```powershell
php artisan kaspi:push-production --sku=SKU --force-content-refresh --dry-run
php artisan kaspi:push-production --limit=10 --force-content-refresh --dry-run
```

Для выполнения заменить `--dry-run` на `--approve=HASH`, сохранив scope.
Обычная команда без force сохраняет прежнее поведение: main/description сохраняются,
галерея дополняется, атрибуты объединяются без перезаписи старых значений.
Команда без `--sku`, `--limit` или `--all` по-прежнему завершается ошибкой.

### Готовность и классификация атрибутов

Force включает полностью заполненные товары, но только активные, с точным валидным
SKU и доступным storefront slug. Наличие SKU не подтверждает Kaspi: каждый товар
проходит существующую проверку настоящего widget/iframe (SKU, merchant, city).
Поиск по названию, предполагаемый URL и приблизительное совпадение SKU не используются.

Нужны одновременно изображения, непустое санитизированное описание и непустые
валидные характеристики. Пустое/некорректное поле или запрещённые атрибуты означают
**пропуск всего товара**. Частичного обновления нет.

В force-режиме весь объект `products.attributes` заменяется санитизированными
характеристиками Kaspi. Нет merge, сохранения старых ключей или whitelist имён.
Бренд, материал, аромат и любые другие обычные характеристики допустимы.

Входящие имена проверяются только по явному списку системных/коммерческих полей
`KaspiRefreshPolicy::FORBIDDEN_KEYS` после нормализации регистра и пробелов.
Совпадение → `commercial_attribute_not_allowed`. Отдельные колонки товара не меняются.

Пустые входящие характеристики → `empty_attributes`; повреждённая структура,
пустые/дублирующиеся пары → отказ без изменений всего товара.
Старый JSON проверяется только на структуру объекта с непустыми уникальными именами
и скалярными значениями, без классификации имён; нарушение → `attributes_invalid`.
Пустой существующий объект, NULL или пустая строка допустимы.
В dry-run выводится `attributes_action=replace_all`. Normal-режим не изменён.

### Approval и защита состояния

Dry-run и выполнение сначала собирают **весь** ready-набор. SHA-256 связывает версию
политики и канонический JSON payload каждого товара в порядке product ID, включая
ID, точный SKU, storefront URL, fingerprint, разрешённый Kaspi URL, порядок image URL,
санитизированное описание, нормализованные характеристики и source identity.
Формат approval остаётся `policy=1`, hash побайтно совместим с прежним алгоритмом.
READY payload записывается по одному в локальный временный NDJSON через `tmpfile()`
после Windows/local guard. Файл содержит контент, но не bearer token; путь и контент
не выводятся в diagnostics. Временный файл удаляется при закрытии в `finally`
после успеха или перехваченной ошибки; destructor также закрывает ресурс.
При аварийном завершении ОС/PHP автоматическое удаление не гарантируется.

API уже выдаёт кандидатов по возрастанию product ID; локальный force-проход проверяет
этот порядок. Нарушение порядка/ошибка файла прерывает batch без approval и POST.
Каждая строка — канонический JSON всего валидированного payload: identity, fingerprint,
content (включая title), source, version и force-флаг. Эти поля уже были связаны hash,
поэтому не удаляются. Preview, counts, действия, HTML и debug не сохраняются в manifest.
SHA-256 читает строки последовательно с прежним JSON framing, без сортировки/сборки
всего набора в RAM. Execution после проверки полного hash читает payload по одному.

После последнего товара stderr сообщает `[finalizing] ready=N`,
`[finalizing] canonical manifest complete`, `[finalizing] approval hash calculated`.
JSON stdout остаётся отдельным. Для force dry-run `--diagnostics` добавляет в stderr
memory_bytes, peak_bytes, ready и manifest_bytes после каждого товара и при завершении:

```powershell
php artisan kaspi:push-production --limit=10 --force-content-refresh --dry-run --diagnostics
```

Память тяжёлого контента ограничена текущим товаром/строкой; размер temp-файла растёт
с READY-набором. Небольшие SKU-dedup и failure metadata по-прежнему растут с batch;
текущая страница содержит не более 100 кандидатов. Нужен доступный локальный temp-диск.

Перед первым POST выполнение заново разрешает/парсит весь scope и сравнивает hash.
Любой дрейф ready-набора, контента или состояния блокирует **все** POST этого запуска.
Ошибка пагинации также запрещает выполнение. После частично успешного запуска нужен
новый dry-run: старый hash более не соответствует изменившимся товарам.

Fingerprint включает все колонки строки товара, все колонки gallery rows (по ID),
merchant/city. Это не только `updated_at`; он также не является хешем файлов на диске.
Перед локальным планированием кандидат повторно запрашивается по точному SKU;
read-only preview подтверждает состояние. Сервер повторно проверяет ID/SKU/storefront
и fingerprint до загрузок и под row lock перед commit. Дрейф → отказ без изменений
этого товара. Существующие bearer/HTTPS/throttle/CDN/merchant/city guards сохранены.
POST-флаг `force_content_refresh` принимает только JSON boolean; отсутствие или
`false` означают обычный импорт. Для GET используются `true`/`false` или `1`/`0`.

### Медиа, транзакции, кэш и ошибки

Все входящие URL сначала скачиваются и проверяются. SHA-256 убирает дубликаты байтов,
сохраняя порядок первого появления. Первый уникальный файл становится main;
оставшиеся — gallery с последовательным `sort_order`. Старые gallery rows удаляются
в одной транзакции с description/attributes/main. Старый `main_image_webp` сбрасывается.
Остальные колонки товара, timestamps, URL history и записи других интеграций не меняются.

Подготовка использует immutable `products/kaspi/{product_id}/{sha256}.{jpg|png|webp}`.
При перехваченном сбое DB откатывается, новые неподключённые файлы удаляются.
После commit удаляются только старые файлы этого формата, принадлежащие этому product ID,
без ссылок из main/main_webp/gallery/path_webp других товаров. Ручные, общие и
неоднозначные файлы остаются на диске, но исключаются из gallery этого товара.
Ошибки удаления после commit возвращаются отдельно как `cleanup_warnings`;
успешное обновление не объявляется откатившимся.

После успеха забываются только `homepage_hits`, `homepage_new_products` и
`sitemap.products` (он содержит image URL). Глобального flush, сброса настроек,
категорий, брендов и redirects нет. Ошибка cache invalidation — отдельное предупреждение.

Жёсткое завершение PHP/ОС между записью hash-файла и commit может оставить
неподключённый hash-файл; распределённой транзакции DB/файловой системы нет.
Не удалять такие файлы вслепую: сначала проверить ссылки и принадлежность.
При отказе хранилища удалить новые файлы возвращается `image_cleanup_failed`.
Временные скачивания используют существующий `php://temp` downloader и закрываются
в `finally`; отдельные persistent temp-каталоги режим не создаёт.

Лимиты не обрезают контент: максимум 12 image URL, 80 входящих атрибутов и 128 KiB JSON.
Превышение возвращает `image_limit_exceeded`, `attribute_limit_exceeded` или
`payload_too_large`. Остальные причины передаются только из безопасного allowlist;
токены, произвольный response body и stack traces не печатаются. Неопределённый исход
POST требует проверки состояния перед повтором; автоматического POST retry нет.

### Отчёт и условия запуска

По каждому товару выводятся ID/SKU/name, текущие и Kaspi counts/presence, действия,
status/reason и fingerprint. Неизвестные после ошибки parser counts — `null`.
Количество текущих фото — уникальные непустые пути main/gallery, не проверка всех байтов.
Kaspi photo count — число URL до серверного SHA-256 dedupe.

Summary: `total_candidates`, `with_kaspi_source`, `resolved`, `resolve_failed`, `parsed`,
`parse_failed`, `ready`, `no_images`, `empty_description`, `empty_attributes`,
`attributes_ambiguous`, `skipped`. Source означает проверенный iframe/control;
resolved — получение уникального URL. Счётчики причин могут пересекаться.
`ready` не гарантирует будущую доступность CDN: dry-run не скачивает серверные медиа.
Execution добавляет `planned`, `processed` (POST attempts), `updated`, `failed`,
`cleanup_warnings`, точные SKU/product_id/status/reason. Ошибка одного POST не
останавливает последующие. Ошибка batch/POST даёт ненулевой exit code; безопасные
пропуски планирования отражены в summary даже при exit code 0.

Нужны согласованные локальная/серверная версии, Windows CLI, `APP_ENV=local`,
`KASPI_LOCAL_BROWSER_ENABLED=true`, локальный Playwright, production HTTPS/token,
совпадающие merchant/city и writable public storage на сервере. Перед выполнением
проверить backup и отсутствие конкурирующих редакторов контента. Готовый набор
хранится в локальном временном файле; для контролируемой проверки использовать ограниченный scope.
GET может создавать обычные HTTP/session/throttle logs/cache; dry-run не меняет
товары, media rows/files и не инвалидирует storefront caches.

Этот workflow не является разрешением на deployment или production execution:
сначала review изменений, затем отдельный согласованный production dry-run.

## Проблема: Laravel Toolkit использует /usr/bin/php

На hoster.kz с CloudLinux существуют два разных PHP:

| | Путь | PDO MySQL | Используется |
|---|---|---|---|
| Системный | `/usr/bin/php` | ❌ НЕТ | Laravel Toolkit |
| PHP Selector | `/opt/alt/php83/usr/bin/php` | ✅ ЕСТЬ | Веб-сайт |

**Именно поэтому `could not find driver`** — Toolkit запускает artisan
через системный PHP без pdo_mysql.

---

## РЕШЕНИЕ: всегда использовать полный путь к PHP 8.3

```bash
# Правильный PHP для artisan на hoster.kz:
/opt/alt/php83/usr/bin/php artisan <команда>
```

---

## Все команды установки

### Шаг 1. Создание директорий storage

```bash
mkdir -p storage/framework/views
mkdir -p storage/framework/sessions
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/testing
mkdir -p storage/logs
mkdir -p bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### Шаг 2. .env — создать и заполнить

```bash
cp .env.example .env
```

Открыть `.env` и задать:
```
APP_URL=https://autohimiki.kz
APP_DEBUG=false
APP_KEY=                     # заполнит команда ниже

DB_HOST=localhost
DB_DATABASE=p-352011_autohimiki_test
DB_USERNAME=p-352011_autohimiki_test
DB_PASSWORD=ВАШ_ПАРОЛЬ
```

### Шаг 3. Генерация APP_KEY

```bash
/opt/alt/php83/usr/bin/php artisan key:generate
```

### Шаг 4. storage:link

```bash
/opt/alt/php83/usr/bin/php artisan storage:link
```

### Шаг 5. optimize:clear

```bash
/opt/alt/php83/usr/bin/php artisan optimize:clear
```

### Шаг 6. Миграции (ОБЯЗАТЕЛЬНО через PHP 8.3!)

```bash
/opt/alt/php83/usr/bin/php artisan migrate --force
```

Ожидаемый результат:
```
Running migrations...
2025_01_001_create_categories_table ............. 12ms DONE
2025_01_002_create_brands_table ................. 8ms  DONE
...
```

### Шаг 7. Сидеры

```bash
/opt/alt/php83/usr/bin/php artisan db:seed --force
```

### Шаг 8. Администратор Filament

```bash
/opt/alt/php83/usr/bin/php artisan make:filament-user
```

### Шаг 9. Кэш production

```bash
/opt/alt/php83/usr/bin/php artisan optimize
```

---

## Удобный псевдоним (алиас) на время сессии SSH

Добавить в начало SSH-сессии:

```bash
alias php='/opt/alt/php83/usr/bin/php'
```

После этого можно писать просто:

```bash
php artisan migrate --force
php artisan db:seed
php artisan make:filament-user
php artisan optimize
```

---

## Постоянный алиас (для всех сессий)

Добавить в `~/.bashrc` или `~/.bash_profile`:

```bash
echo "alias php='/opt/alt/php83/usr/bin/php'" >> ~/.bashrc
source ~/.bashrc
```

---

## Wrapper-скрипт artisan83

В проекте есть готовый wrapper `artisan83`:

```bash
bash artisan83 migrate --force
bash artisan83 db:seed
bash artisan83 make:filament-user
```

---

## Как настроить Laravel Toolkit на правильный PHP

В Plesk → Laravel Toolkit → Settings:
```
PHP Path: /opt/alt/php83/usr/bin/php
```

Если такой настройки нет в UI — использовать SSH напрямую.

---

## Диагностика

```bash
# Запустить диагностику:
bash diagnose.sh

# Проверить вручную:
/usr/bin/php -r "echo implode(', ', PDO::getAvailableDrivers());"
# Вероятный вывод: odbc, pgsql, sqlite  (mysql ОТСУТСТВУЕТ)

/opt/alt/php83/usr/bin/php -r "echo implode(', ', PDO::getAvailableDrivers());"
# Вероятный вывод: mysql, odbc, pgsql, sqlite  ✅

# Тест подключения к БД:
/opt/alt/php83/usr/bin/php -r "
new PDO('mysql:host=localhost;dbname=p-352011_autohimiki_test',
        'p-352011_autohimiki_test', 'ПАРОЛЬ');
echo 'OK';
"
```

---

## Типичные ошибки и решения

### could not find driver
```
Причина:  artisan запущен через /usr/bin/php (нет pdo_mysql)
Решение:  использовать /opt/alt/php83/usr/bin/php artisan migrate
```

### SQLSTATE[HY000] [2002] Connection refused
```
Причина:  DB_HOST=127.0.0.1 — на shared хостинге нужен localhost
Решение:  DB_HOST=localhost в .env
```

### SQLSTATE[HY000] [1045] Access denied
```
Причина:  неверный DB_USERNAME или DB_PASSWORD
Решение:  скопировать точные данные из Plesk → Databases
```

### Class "Str" not found (в config/)
```
Причина:  отсутствует use Illuminate\Support\Str в config/*.php
Файлы:    config/database.php, config/cache.php, config/session.php
Решение:  уже исправлено в текущей версии архива
```
