# Команды для autohimiki.kz на hoster.kz (Plesk + CloudLinux)

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
