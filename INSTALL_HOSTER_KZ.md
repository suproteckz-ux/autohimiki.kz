# Установка на hoster.kz (Plesk + PHP 8.3)

## Проблема: отсутствует ext-gd

При запуске `composer install` вы увидите ошибку:
```
phpoffice/phpspreadsheet require ext-gd * -> it is missing from your system
```

**ext-gd нужен** для двух задач:
- `phpoffice/phpspreadsheet` — чтение/запись Excel-файлов  
- `intervention/image` — конвертация изображений в WebP

---

## Решение 1 (рекомендуется): включить ext-gd через Plesk

1. Войти в **Plesk** → раздел **PHP**
2. Найти версию **PHP 8.3** (путь: `/opt/alt/php83/`)
3. Перейти в **PHP Settings** для вашего домена
4. В разделе расширений включить галочку **gd**
5. Сохранить → нажать **Apply**

Затем запустить установку заново через Plesk → **Laravel Toolkit** → **Deploy**.

---

## Решение 2: включить вручную через php.ini

Открыть файл `/opt/alt/php83/etc/php.ini` и раскомментировать:
```ini
extension=gd
```

Или добавить в `/opt/alt/php83/link/conf/default.ini`:
```ini
extension=gd.so
```

Перезапустить PHP-FPM:
```bash
systemctl restart alt-php83-fpm
```

---

## Решение 3: временный обход (если ext-gd включить нельзя)

Если хостинг не позволяет включить ext-gd, можно запустить установку с флагом:
```bash
composer install --no-dev --optimize-autoloader --ignore-platform-req=ext-gd
```

**Важно:** в этом случае конвертация в WebP работать НЕ будет.
Импорт Excel-файлов также будет недоступен.
Используйте только как временное решение.

---

## Проверка после включения ext-gd

```bash
php -m | grep gd
# Ожидаем: gd

php -r "echo gd_info()['GD Version'];"
# Ожидаем: bundled (...)
```

---

## Предупреждение pdo_oci — игнорировать

```
PHP Warning: Unable to load dynamic library 'pdo_oci.so'
```
Это **не ошибка** — просто предупреждение о том, что библиотека Oracle
не установлена. На наш проект (MySQL) не влияет. Игнорировать.

