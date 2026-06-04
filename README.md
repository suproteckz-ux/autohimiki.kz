# Autohimiki.kz

Интернет-магазин автохимии в Алматы, Казахстан.

## Стек

- **Backend:** Laravel 12
- **Админка:** Filament 4
- **Frontend:** Blade + Alpine.js + Tailwind CSS 3
- **БД:** MySQL 8 / MariaDB 10.6+
- **Очереди:** Laravel Queue (database)
- **Импорт:** Maatwebsite Excel (XLS/XLSX/CSV)
- **Изображения:** Intervention Image (WebP)

## Быстрый старт

```bash
# 1. Клонировать
git clone ... autohimiki.kz && cd autohimiki.kz

# 2. Зависимости
composer install
npm install

# 3. Окружение
cp .env.example .env
php artisan key:generate

# 4. БД
php artisan migrate
php artisan db:seed

# 5. Фронтенд
npm run build

# 6. Хранилище
php artisan storage:link

# 7. Первый администратор
php artisan make:filament-user

# 8. Запуск
php artisan serve
```

## Документация

Полная документация разработки — в `/docs/` (этапы 1-8).

## Деплой

```bash
bash deploy/deploy.sh
```

## Импорт из 1С

1. Перейти в /admin → Импорт товаров
2. Загрузить XLSX-файл из 1С
3. Выбрать режим: "Обновление из 1С" (prices_only)
4. Маппинг определяется автоматически
5. Запустить импорт
