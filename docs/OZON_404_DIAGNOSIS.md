# Ozon HTTP 404: проверка URL

Дата: 23.09.2026. Проверен код autohimiki.kz на базе commit `446c620` и
read-only исходники `C:/Users/anton/OneDrive/Documents/autohimiya-laravel`.
Production и реальные Seller API запросы не использовались.

## Установленные факты

| Параметр | Текущее значение |
|---|---|
| Base host | `https://api-seller.ozon.ru` |
| Источник base host | Константа OzonClient (до диагностики — та же строка в коде) |
| ENV key для base URL | Нет: `OZON_BASE_URL` не читается |
| Seller URL | `https://api-seller.ozon.ru/v1/seller/info` |
| Seller method/body | POST, строго `{}` |
| Seller headers | Client-Id, Api-Key, Content-Type: application/json, Accept: application/json |
| Warehouse URL | `https://api-seller.ozon.ru/v2/warehouse/list` |
| Warehouse method/body | POST, limit=100 и cursor |
| Redirects | Отключены |

`config/ozon.php` и `.env.example` не содержат base_url/OZON_BASE_URL.
В локальной `.env` ключа OZON_BASE_URL нет; значения других секретов не выводились.
Даже наличие старого OZON_BASE_URL в окружении не меняет URL этой реализации.
В production `.env` не заглядывали: наличие там такого ключа не установлено.
Назначать ему значение не требуется; ожидаемый host зафиксирован кодом.
Не нужно добавлять `/api`, `/v1` или менять `.env` для исправления неустановленной причины.

В OzonSellerInfo и OzonWarehouses URL не собираются: команды вызывают клиент.
Дополнительных `/api`, `/v1/v1`, относительного baseUrl или GET в проверенной цепочке нет.

## Старый проект и публичные первичные источники

В старом `OzonApiClient::BASE_URL` тот же host. Старые OzonConnectionService и
OzonWarehouseService используют соответственно `/v1/seller/info` и `/v2/warehouse/list`.
История `6a9543c` фиксирует замену warehouse v1 на v2, `413e96e` — JSON `{}` для seller.

Первичные публичные уведомления Ozon подтверждают направление **v1 → v2**, не наоборот:

- [Ozon, уведомления от 24 марта 2026](https://t.me/s/OzonEnSellerAPI?before=313):
  v1/warehouse/list deprecated, объявлен переход на v2; там же указан seller/info.
- [Ozon, обновления июня 2026](https://t.me/s/OzonSellerAPI/660): обновление company.currency в seller/info.

Полную актуальную OpenAPI-схему получить не удалось: docs.ozon.ru/api/seller
возвращает redirect loop в веб-инструменте. Это ограничение проверки документации,
а не доказанная причина production-404. Сторонние старые примеры с warehouse v1
не перевешивают уведомление поставщика об устаревании. Warehouse остаётся **v2**;
fallback/автоматического запроса v1 не добавлено.

## Новая безопасная команда

```bash
php artisan ozon:diagnose
```

Печатает host, endpoints, effective URLs, POST/JSON-контракт seller и только
yes/no для credentials из effective Laravel config. Самих ключей, Client ID,
значений старых URL ENV и содержимого config cache не печатает.
Не выполняет HTTP, не открывает каталог товаров, не пишет БД, не включает экспорт.
Работает без credentials и при OZON_ENABLED=false.
URL диагностики и реальных seller/warehouse запросов используют общие константы,
поэтому диагностика не является независимой вручную набранной копией адресов.

## Причина 404: предел вывода

**Точная причина production-404 пока не установлена.** Проверкой кода исключена
гипотеза неправильной конкатенации URL в данной версии. Не подтверждены ошибочные
env/config или необходимость перехода на warehouse v1. PHP warnings
pdo_oci/PDO/mysqlnd не объявлены причиной HTTP-ответа без доказательств.

`ozon:test-connection` и `ozon:seller-info` вызывают один и тот же endpoint, поэтому
три команды здесь означают два разных URL, а не три независимых отказа.
По одному статусу 404 нельзя установить, кто сформировал ответ: Ozon или посредник,
какая версия кода реально исполнялась и какой сетевой маршрут использовался.

Следующее необходимое свидетельство — вывод `ozon:diagnose` именно из production
checkout под тем же PHP, которым запускались команды. Если URL совпадут, для
дальнейшего разбора нужен безопасный HTTP-отчёт с кодом, Content-Type и request ID
ответа, без Api-Key/Client-Id и request headers. Эти данные здесь не получены.
Нельзя объявлять исправление 404 успешным до такой проверки.

## Область изменений

Проверки: `php artisan test --filter=Ozon` — **55 passed, 526 assertions**.
Pint и `git diff --check` — без ошибок. `ozon:diagnose` выполнена локально:
оба URL совпали с таблицей выше; Client ID/API key — no/no для локального окружения.
Это не свидетельствует о наличии или отсутствии credentials на production.

- `app/Services/Ozon/OzonClient.php`: общие константы read-only URL и diagnostics().
- `app/Console/Commands/OzonDiagnose.php`: новая команда без сети/БД.
- `tests/Feature/OzonDiagnoseTest.php`: точные URL, совпадение с фактическими fake
  запросами, POST, `{}`, заголовки, отсутствие двойного /v1 и утечки секретов,
  отсутствие HTTP/SQL в diagnose, игнорирование старого ENV/config base URL.
- Этот отчёт и ссылка на диагностику в `docs/OZON_EXPORT.md`.

Не изменены config/.env, product import, price protection, stock logic, category
mapping, taxonomy, payload, OzonSellerInfo и OzonWarehouses. Commit/push и production
действия не выполнялись. **Полное дерево категорий Ozon не скачивается.**
