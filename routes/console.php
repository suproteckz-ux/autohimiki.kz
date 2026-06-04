<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════════
// Расписание задач autohimiki.kz
// Запускается через: php artisan schedule:work (supervisor)
// ════════════════════════════════════════════════════════════════

// ── ИСПРАВЛЕНИЕ DO-3: Ежедневный бэкап БД ────────────────────
Schedule::call(function () {
    $backupDir = storage_path('backups');

    if (! is_dir($backupDir)) {
        mkdir($backupDir, 0750, true);
    }

    $filename = 'backup_' . now()->format('Y-m-d_H-i-s') . '.sql.gz';
    $filepath = "{$backupDir}/{$filename}";

    $host    = config('database.connections.mysql.host', '127.0.0.1');
    $port    = config('database.connections.mysql.port', '3306');
    $dbname  = config('database.connections.mysql.database');
    $user    = config('database.connections.mysql.username');
    $pass    = config('database.connections.mysql.password');

    // Используем --defaults-extra-file для безопасной передачи пароля
    // (избегаем пароль в аргументах командной строки — виден в ps aux)
    $mycnfContent = "[client]\npassword={$pass}\n";
    $mycnfFile    = tempnam(sys_get_temp_dir(), 'mysql_');
    file_put_contents($mycnfFile, $mycnfContent);
    chmod($mycnfFile, 0600);

    $cmd = sprintf(
        'mysqldump --defaults-extra-file=%s -h%s -P%s -u%s %s | gzip > %s',
        escapeshellarg($mycnfFile),
        escapeshellarg($host),
        escapeshellarg($port),
        escapeshellarg($user),
        escapeshellarg($dbname),
        escapeshellarg($filepath)
    );

    exec($cmd, $output, $exitCode);

    // Удаляем временный файл с паролем
    @unlink($mycnfFile);

    if ($exitCode !== 0 || ! file_exists($filepath)) {
        Log::error("Backup: ошибка создания бэкапа", [
            'exit_code' => $exitCode,
            'file'      => $filepath,
        ]);
        return;
    }

    $size = round(filesize($filepath) / 1024 / 1024, 2);
    Log::info("Backup: создан {$filename} ({$size} MB)");

    // Удаляем бэкапы старше 30 дней
    $deleted = 0;
    foreach (glob("{$backupDir}/backup_*.sql.gz") as $file) {
        if (filemtime($file) < now()->subDays(30)->timestamp) {
            @unlink($file);
            $deleted++;
        }
    }

    if ($deleted > 0) {
        Log::info("Backup: удалено {$deleted} устаревших бэкапов");
    }
})->dailyAt('02:00')
  ->name('db:backup')
  ->withoutOverlapping()
  ->onFailure(function () {
      Log::critical("Backup: задача не выполнена! Требуется ручная проверка.");
  });

// ── Прогрев кэша главной страницы ────────────────────────────
Schedule::call(function () {
    \App\Services\CacheService::forgetHomepage();
    \App\Services\CacheService::homepageCategories();
    \App\Services\CacheService::homepageHits();
    \App\Services\CacheService::homepageNewProducts();
    \App\Services\CacheService::homepageBrands();
})->everyThirtyMinutes()
  ->between('8:00', '22:00')
  ->name('cache:warmup-homepage')
  ->withoutOverlapping();

// ── Сброс кэша sitemap ────────────────────────────────────────
Schedule::call(function () {
    \App\Services\CacheService::forgetSitemap();
})->dailyAt('04:00')
  ->name('sitemap:refresh')
  ->withoutOverlapping();

// ── Очистка файлов импорта старше 30 дней ────────────────────
Schedule::call(function () {
    $disk   = \Illuminate\Support\Facades\Storage::disk('public');
    $files  = $disk->files('imports');
    $cutoff = now()->subDays(30)->timestamp;
    $deleted = 0;

    foreach ($files as $file) {
        if ($disk->lastModified($file) < $cutoff) {
            $disk->delete($file);
            $deleted++;
        }
    }

    if ($deleted > 0) {
        Log::info("Scheduler: удалено {$deleted} старых файлов импорта");
    }
})->weekly()
  ->sundays()
  ->at('03:00')
  ->name('import:cleanup-files')
  ->withoutOverlapping();

// ── Очистка старых batch-записей импорта ─────────────────────
Schedule::call(function () {
    $count = \App\Models\ImportBatch::where('created_at', '<', now()->subDays(90))
        ->where('status', 'done')
        ->count();

    \App\Models\ImportBatch::where('created_at', '<', now()->subDays(90))
        ->where('status', 'done')
        ->delete();

    if ($count > 0) {
        Log::info("Scheduler: удалено {$count} старых записей импорта");
    }
})->monthly()
  ->name('import:cleanup-old-batches')
  ->withoutOverlapping();

// ── Мониторинг зависших импортов ──────────────────────────────
Schedule::call(function () {
    $stuck = \App\Models\ImportBatch::where('status', 'processing')
        ->where('started_at', '<', now()->subHours(2))
        ->get();

    foreach ($stuck as $batch) {
        $batch->update([
            'status'      => 'failed',
            'finished_at' => now(),
        ]);
        Log::warning("Scheduler: зависший импорт #{$batch->id} помечен как failed");
    }
})->hourly()
  ->name('import:check-stuck')
  ->withoutOverlapping();

// ── Очистка failed jobs старше 7 дней ────────────────────────
Schedule::call(function () {
    DB::table('failed_jobs')
        ->where('failed_at', '<', now()->subDays(7)->toDateTimeString())
        ->delete();
})->weekly()
  ->name('queue:cleanup-failed')
  ->withoutOverlapping();

// ── Очистка прогресс-кэша завершённых импортов ───────────────
Schedule::call(function () {
    $batches = \App\Models\ImportBatch::whereIn('status', ['done', 'failed'])
        ->where('finished_at', '<', now()->subHours(4))
        ->pluck('id');

    foreach ($batches as $id) {
        \Illuminate\Support\Facades\Cache::forget("import_progress_{$id}");
    }
})->hourly()
  ->name('import:cleanup-progress-cache')
  ->withoutOverlapping();
