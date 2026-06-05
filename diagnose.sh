#!/bin/bash
# ══════════════════════════════════════════════════════════════
# diagnose.sh — диагностика PHP на CloudLinux / hoster.kz
# Запуск через SSH: bash diagnose.sh
# ══════════════════════════════════════════════════════════════

echo "══════════════════════════════════════════"
echo " ДИАГНОСТИКА PHP + PDO"
echo "══════════════════════════════════════════"
echo ""

# 1. Системный PHP
echo "--- /usr/bin/php (системный, используется Toolkit) ---"
if command -v /usr/bin/php &>/dev/null; then
    /usr/bin/php -v 2>/dev/null | head -1
    echo -n "PDO drivers: "
    /usr/bin/php -r "echo implode(', ', PDO::getAvailableDrivers());" 2>/dev/null || echo "PDO недоступен"
else
    echo "отсутствует"
fi

echo ""
echo "--- /opt/alt/php83/usr/bin/php (PHP Selector 8.3) ---"
if command -v /opt/alt/php83/usr/bin/php &>/dev/null; then
    /opt/alt/php83/usr/bin/php -v 2>/dev/null | head -1
    echo -n "PDO drivers: "
    /opt/alt/php83/usr/bin/php -r "echo implode(', ', PDO::getAvailableDrivers());" 2>/dev/null
else
    echo "отсутствует"
fi

echo ""
echo "--- which php ---"
which php 2>/dev/null
php -v 2>/dev/null | head -1

echo ""
echo "--- .env DB настройки ---"
grep "^DB_" .env 2>/dev/null || echo ".env не найден"

echo ""
echo "--- Тест подключения к БД через PHP 8.3 ---"
DB_HOST=$(grep "^DB_HOST=" .env | cut -d'=' -f2)
DB_NAME=$(grep "^DB_DATABASE=" .env | cut -d'=' -f2)
DB_USER=$(grep "^DB_USERNAME=" .env | cut -d'=' -f2)
DB_PASS=$(grep "^DB_PASSWORD=" .env | cut -d'=' -f2)

/opt/alt/php83/usr/bin/php -r "
try {
    \$pdo = new PDO('mysql:host=${DB_HOST};dbname=${DB_NAME}', '${DB_USER}', '${DB_PASS}');
    echo '✅ Подключение к БД успешно' . PHP_EOL;
} catch (Exception \$e) {
    echo '❌ ' . \$e->getMessage() . PHP_EOL;
}
" 2>/dev/null

echo ""
echo "══════════════════════════════════════════"
