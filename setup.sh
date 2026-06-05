#!/bin/bash
# ══════════════════════════════════════════════════════════════
# setup.sh — первичная настройка autohimiki.kz на хостинге
# Запуск: bash setup.sh
# ══════════════════════════════════════════════════════════════

set -e

# Определяем PHP — hoster.kz использует /opt/alt/php83/usr/bin/php
if command -v /opt/alt/php83/usr/bin/php &>/dev/null; then
    PHP="/opt/alt/php83/usr/bin/php"
elif command -v php8.3 &>/dev/null; then
    PHP="php8.3"
elif command -v php &>/dev/null; then
    PHP="php"
else
    echo "❌ PHP не найден"; exit 1
fi

echo "PHP: $($PHP -r 'echo PHP_VERSION;')"
echo "Путь: $PHP"
echo ""

# ── ШАГ 1: storage/framework/* ───────────────────────────────
echo "📁 Создаём директории storage/framework..."
mkdir -p storage/framework/views
mkdir -p storage/framework/sessions
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/testing
mkdir -p storage/logs
mkdir -p storage/backups
mkdir -p bootstrap/cache
mkdir -p storage/app/public/{products,categories,brands,blog,imports,site}
echo "✅ Директории созданы"

# ── ШАГ 2: Права доступа ─────────────────────────────────────
echo ""
echo "🔐 Устанавливаем права..."
chmod -R 775 storage
chmod -R 775 bootstrap/cache
echo "✅ Права установлены"

# ── ШАГ 3: .env из шаблона ───────────────────────────────────
echo ""
if [ ! -f ".env" ]; then
    echo "📄 Создаём .env из .env.example..."
    cp .env.example .env
    echo "✅ .env создан"
    echo "⚠️  Заполни DB_DATABASE, DB_USERNAME, DB_PASSWORD в .env!"
else
    echo "ℹ️  .env уже существует — пропускаем"
fi

# ── ШАГ 4: APP_KEY ───────────────────────────────────────────
echo ""
CURRENT_KEY=$(grep "^APP_KEY=" .env | cut -d'=' -f2)
if [ -z "$CURRENT_KEY" ]; then
    echo "🔑 Генерируем APP_KEY..."
    $PHP artisan key:generate --ansi
    echo "✅ APP_KEY сгенерирован"
else
    echo "ℹ️  APP_KEY уже задан — пропускаем"
fi

# ── ШАГ 5: storage:link ──────────────────────────────────────
echo ""
if [ ! -L "public/storage" ]; then
    echo "🔗 Создаём storage:link..."
    $PHP artisan storage:link
    echo "✅ storage:link создан"
else
    echo "ℹ️  storage:link уже существует — пропускаем"
fi

# ── ШАГ 6: optimize:clear ────────────────────────────────────
echo ""
echo "🗑️  Очищаем кэши..."
$PHP artisan optimize:clear
echo "✅ Кэши очищены"

# ── ШАГ 7: Итог ──────────────────────────────────────────────
echo ""
echo "══════════════════════════════════════════════════════"
echo " ✅ Базовая настройка завершена!"
echo "══════════════════════════════════════════════════════"
echo ""
echo " Следующие шаги:"
echo ""
echo " 1. Отредактируй .env — заполни параметры БД:"
echo "    DB_DATABASE=autohimiki"
echo "    DB_USERNAME=ваш_пользователь"
echo "    DB_PASSWORD=ваш_пароль"
echo "    APP_URL=https://autohimiki.kz"
echo ""
echo " 2. Запусти миграции:"
echo "    $PHP artisan migrate"
echo ""
echo " 3. Заполни базовые данные:"
echo "    $PHP artisan db:seed"
echo ""
echo " 4. Создай администратора Filament:"
echo "    $PHP artisan make:filament-user"
echo ""
echo " 5. Скэшируй конфигурацию:"
echo "    $PHP artisan optimize"
echo ""
