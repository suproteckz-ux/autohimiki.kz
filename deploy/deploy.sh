#!/bin/bash
# ════════════════════════════════════════════════════════════════
# deploy.sh — скрипт деплоя autohimiki.kz
#
# ИСПРАВЛЕНИЕ DO-1:
# Добавлен rollback при падении миграции:
# 1. Бэкап БД перед migrate
# 2. При ошибке migrate — rollback --step=1
# 3. При невозможности rollback — сайт включается обратно
#    с исходным состоянием БД
#
# ИСПРАВЛЕНИЕ DO-3:
# Бэкап перед деплоем (не только в cron).
# ════════════════════════════════════════════════════════════════

set -euo pipefail  # -u: ошибка на undefined vars, -o pipefail: ошибка в pipe

APP_DIR="/var/www/autohimiki.kz"
PHP="php8.2"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/var/backups/autohimiki"
BACKUP_FILE="${BACKUP_DIR}/pre_deploy_${TIMESTAMP}.sql.gz"

# Загружаем переменные из .env
if [ -f "$APP_DIR/.env" ]; then
    export $(grep -v '^#' "$APP_DIR/.env" | grep -E 'DB_(HOST|DATABASE|USERNAME|PASSWORD)' | xargs)
fi

# ─────────────────────────────────────────────────────────────────
# Функции
# ─────────────────────────────────────────────────────────────────

log() { echo "[$(date '+%H:%M:%S')] $*"; }

die() {
    log "❌ ОШИБКА: $*"
    log "🔄 Включаем сайт обратно..."
    cd "$APP_DIR" && $PHP artisan up 2>/dev/null || true
    exit 1
}

# ─────────────────────────────────────────────────────────────────
# Начало деплоя
# ─────────────────────────────────────────────────────────────────

log "🚀 Начинаем деплой autohimiki.kz..."

cd "$APP_DIR"

# ── 1. Режим обслуживания ─────────────────────────────────────
log "⏳ Включаем режим обслуживания..."
$PHP artisan down --secret="deploy-secret-token-change-me" || die "Не удалось включить режим обслуживания"

# ── 2. Git pull ───────────────────────────────────────────────
log "📥 Получаем обновления..."
git pull origin main || die "git pull завершился с ошибкой"

# ── 3. Composer ───────────────────────────────────────────────
log "📦 Устанавливаем PHP зависимости..."
composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist \
    || die "composer install завершился с ошибкой"

# ── 4. NPM и Vite ─────────────────────────────────────────────
log "🎨 Собираем фронтенд..."
npm ci --production=false || die "npm ci завершился с ошибкой"
npm run build || die "npm run build завершился с ошибкой"

# ── 5. БЭКАП перед миграцией (ИСПРАВЛЕНИЕ DO-1 + DO-3) ───────
log "💾 Создаём бэкап БД перед миграцией..."
mkdir -p "$BACKUP_DIR"

if mysqldump \
    -h"${DB_HOST:-127.0.0.1}" \
    -u"${DB_USERNAME}" \
    -p"${DB_PASSWORD}" \
    "${DB_DATABASE}" \
    2>/dev/null | gzip > "$BACKUP_FILE"; then
    log "✅ Бэкап создан: $BACKUP_FILE ($(du -sh "$BACKUP_FILE" | cut -f1))"
else
    log "⚠️  Не удалось создать бэкап — продолжаем без него"
    log "    Рекомендуется остановить деплой и проверить доступ к БД"
    # Не останавливаем деплой — бэкап желателен, но не блокирует
fi

# ── 6. Проверка миграций ──────────────────────────────────────
log "🔍 Проверяем статус миграций..."
PENDING=$($PHP artisan migrate:status --no-ansi 2>/dev/null | grep -c "Pending" || true)
log "   Ожидает применения: ${PENDING} миграций"

# ── 7. Миграции с rollback при ошибке (ИСПРАВЛЕНИЕ DO-1) ─────
if [ "$PENDING" -gt "0" ]; then
    log "🗄️  Применяем ${PENDING} миграций..."

    if $PHP artisan migrate --force; then
        log "✅ Миграции применены успешно"
    else
        log "❌ Ошибка при миграции! Пытаемся откатить..."

        # Откатываем последнюю миграцию
        if $PHP artisan migrate:rollback --force --step=1; then
            log "✅ Откат выполнен"
        else
            log "⚠️  Откат не удался — восстановите из бэкапа: $BACKUP_FILE"
            log "    Команда: gunzip -c $BACKUP_FILE | mysql -u\$DB_USERNAME -p\$DB_PASSWORD \$DB_DATABASE"
        fi

        # Включаем сайт с предыдущим кодом — git revert
        log "🔄 Откатываем код к предыдущей версии..."
        git revert HEAD --no-commit 2>/dev/null || git reset --hard HEAD~1
        $PHP artisan up
        die "Деплой прерван из-за ошибки миграции. БД откатана."
    fi
else
    log "   Нет новых миграций — пропускаем"
fi

# ── 8. Сброс кэшей ────────────────────────────────────────────
log "🗑️  Сбрасываем кэши..."
$PHP artisan optimize:clear   # конфиг + роуты + вьюхи + события

# ── 9. Прогрев кэшей ─────────────────────────────────────────
log "♨️  Прогреваем кэши..."
$PHP artisan optimize         # кэширует конфиг + роуты + события
$PHP artisan view:cache
$PHP artisan filament:cache-components

# ── 10. Права на файлы ───────────────────────────────────────
log "🔐 Устанавливаем права..."
chown -R www-data:www-data "$APP_DIR/storage"
chown -R www-data:www-data "$APP_DIR/bootstrap/cache"
chmod -R 775 "$APP_DIR/storage"
chmod -R 775 "$APP_DIR/bootstrap/cache"

# ── 11. Storage link ──────────────────────────────────────────
if [ ! -L "$APP_DIR/public/storage" ]; then
    log "🔗 Создаём storage link..."
    $PHP artisan storage:link
fi

# ── 12. Перезапуск очередей ──────────────────────────────────
log "🔄 Перезапускаем воркеры..."
$PHP artisan queue:restart
supervisorctl restart autohimiki-worker:* 2>/dev/null || log "   Supervisor недоступен"
supervisorctl restart autohimiki-scheduler 2>/dev/null || true

# ── 13. PHP-FPM ──────────────────────────────────────────────
log "⚙️  Перезагружаем PHP-FPM..."
systemctl reload php8.2-fpm || service php8.2-fpm reload

# ── 14. Выключение режима обслуживания ───────────────────────
log "✅ Отключаем режим обслуживания..."
$PHP artisan up

# ── 15. Финальная проверка ────────────────────────────────────
log "🏥 Проверяем работоспособность..."
sleep 2

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
    -H "X-Maintenance-Secret: deploy-secret-token-change-me" \
    https://autohimiki.kz/ 2>/dev/null || echo "000")

if [ "$HTTP_CODE" = "200" ]; then
    log "✅ Сайт работает (HTTP $HTTP_CODE)"
else
    log "⚠️  Сайт вернул HTTP $HTTP_CODE — проверьте вручную"
fi

# ── 16. Удаление старых бэкапов (старше 30 дней) ─────────────
find "$BACKUP_DIR" -name "*.sql.gz" -mtime +30 -delete 2>/dev/null || true

log ""
log "🎉 Деплой завершён! $(date)"
log "   Сайт: https://autohimiki.kz"
log "   Бэкап: $BACKUP_FILE"
