<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * AuthServiceProvider
 *
 * ИСПРАВЛЕНИЕ SEC-4 + SEC-5:
 * Провайдер регистрирует Gate-политики для ролей admin/manager.
 *
 * Проверить что зарегистрирован в bootstrap/providers.php.
 * Проверить что поле role существует в таблице users (миграция SEC-5).
 */
class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [];

    public function boot(): void
    {
        // ── Доступ к Filament-панели ──────────────────────────────
        // Используется в AdminPanelProvider::authorization()
        Gate::define('access-admin', function (User $user) {
            return $user->isManager(); // admin + manager
        });

        // ── Управление настройками сайта ──────────────────────────
        // Телефон, WhatsApp, аналитика, лого — только admin
        Gate::define('manage-settings', function (User $user) {
            return $user->isAdmin();
        });

        // ── Управление пользователями ─────────────────────────────
        // Создание/удаление других админов — только admin
        Gate::define('manage-users', function (User $user) {
            return $user->isAdmin();
        });

        // ── Удаление контента ─────────────────────────────────────
        // Удаление товаров/категорий/статей — только admin
        Gate::define('delete-content', function (User $user) {
            return $user->isAdmin();
        });

        // ── Запуск импорта ────────────────────────────────────────
        Gate::define('run-import', function (User $user) {
            return $user->isManager();
        });

        // ── Управление редиректами ────────────────────────────────
        Gate::define('manage-redirects', function (User $user) {
            return $user->isAdmin();
        });
    }
}
