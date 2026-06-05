# Команды установки autohimiki.kz на hoster.kz (Plesk)

## Путь к PHP 8.3 на hoster.kz

```bash
# hoster.kz использует нестандартный путь к PHP
/opt/alt/php83/usr/bin/php --version

# Для удобства — создать алиас в текущей сессии:
alias php='/opt/alt/php83/usr/bin/php'
alias composer='/opt/alt/php83/usr/bin/php /usr/local/bin/composer'
```

---

## Шаг 1. Создание storage/framework/*

```bash
mkdir -p storage/framework/views
mkdir -p storage/framework/sessions
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/testing
mkdir -p storage/logs
mkdir -p storage/backups
mkdir -p bootstrap/cache
mkdir -p storage/app/public/products
mkdir -p storage/app/public/categories
mkdir -p storage/app/public/brands
mkdir -p storage/app/public/blog
mkdir -p storage/app/public/imports
mkdir -p storage/app/public/site

# Права
chmod -R 775 storage
chmod -R 775 bootstrap/cache
```

---

## Шаг 2. Создание .env из шаблона

```bash
cp .env.example .env
```

Затем открыть файл и заполнить:
```
APP_URL=https://autohimiki.kz
APP_DEBUG=false

DB_HOST=127.0.0.1
DB_DATABASE=autohimiki          # имя БД из Plesk
DB_USERNAME=autohimiki_user     # пользователь БД из Plesk
DB_PASSWORD=ваш_пароль

SESSION_SECURE_COOKIE=true
```

---

## Шаг 3. Генерация APP_KEY

```bash
/opt/alt/php83/usr/bin/php artisan key:generate
```

Ожидаемый вывод:
```
Application key set successfully.
```

---

## Шаг 4. Символическая ссылка storage

```bash
/opt/alt/php83/usr/bin/php artisan storage:link
```

Ожидаемый вывод:
```
The [public/storage] link has been connected to [storage/app/public].
```

---

## Шаг 5. Очистка всех кэшей

```bash
/opt/alt/php83/usr/bin/php artisan optimize:clear
```

Ожидаемый вывод:
```
Cached events cleared successfully.
Cached views cleared successfully.
Compiled views cleared successfully.
Application cache cleared successfully.
Route cache cleared successfully.
Configuration cache cleared successfully.
Compiled services and packages files removed successfully.
```

---

## Шаг 6. Миграции

```bash
/opt/alt/php83/usr/bin/php artisan migrate
```

---

## Шаг 7. Сидеры (базовые данные)

```bash
/opt/alt/php83/usr/bin/php artisan db:seed
```

---

## Шаг 8. Создание администратора

```bash
/opt/alt/php83/usr/bin/php artisan make:filament-user
```

---

## Шаг 9. Кэш для production

```bash
/opt/alt/php83/usr/bin/php artisan optimize
```

---

## Все команды одной строкой (после настройки .env)

```bash
PHP=/opt/alt/php83/usr/bin/php

mkdir -p storage/framework/{views,sessions,cache/data,testing} storage/logs storage/backups bootstrap/cache storage/app/public/{products,categories,brands,blog,imports,site}
chmod -R 775 storage bootstrap/cache

cp .env.example .env
# !! Заполни .env вручную !!

$PHP artisan key:generate
$PHP artisan storage:link
$PHP artisan optimize:clear
$PHP artisan migrate
$PHP artisan db:seed
$PHP artisan make:filament-user
$PHP artisan optimize
```

---

## Решение типичных ошибок

### InvalidArgumentException: Please provide a valid cache path
```bash
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/views
mkdir -p storage/framework/sessions
chmod -R 775 storage
/opt/alt/php83/usr/bin/php artisan optimize:clear
```

### Class "Str" not found (в config/)
Убедиться что в `config/database.php`, `config/cache.php`, `config/session.php`
есть строка `use Illuminate\Support\Str;` в начале файла.

### PHP Warning: Unable to load dynamic library 'pdo_oci.so'
Игнорировать — это предупреждение Oracle PDO, не влияет на MySQL.

### The stream or file "storage/logs/laravel.log" could not be opened
```bash
mkdir -p storage/logs
chmod -R 775 storage/logs
touch storage/logs/laravel.log
chmod 664 storage/logs/laravel.log
```
