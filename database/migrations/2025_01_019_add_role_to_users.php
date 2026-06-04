<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * ИСПРАВЛЕНИЕ SEC-5:
 * Поле role использовалось в логике безопасности (User::isAdmin(), Gate),
 * но не существовало в миграции users.
 *
 * Без этой миграции:
 * - $user->role → null (всегда)
 * - $user->isAdmin() → false (всегда)
 * - Gate 'access-admin' → всегда false
 * - Никто не мог войти в /admin через Gate-проверку
 *
 * Значения:
 * - 'admin'   → полный доступ: все разделы + настройки + пользователи
 * - 'manager' → доступ к товарам, заявкам, импорту; без настроек сайта
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Добавляем ENUM с дефолтом manager (безопаснее — не получает лишних прав)
            $table->enum('role', ['admin', 'manager'])
                  ->default('manager')
                  ->after('email');
        });

        // Первый пользователь (созданный через make:filament-user) → admin
        DB::table('users')
            ->where('id', 1)
            ->update(['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
