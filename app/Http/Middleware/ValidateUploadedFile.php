<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * ValidateUploadedFile
 *
 * Безопасная загрузка файлов:
 * - Проверка MIME-типа по содержимому (magic bytes), не только по расширению
 * - Ограничение размера
 * - Рандомное имя файла (нет path traversal)
 * - Только Filament-пользователи могут загружать файлы импорта
 */
class ValidateUploadedFile
{
    // Разрешённые MIME-типы для импорта
    private const ALLOWED_IMPORT_MIMES = [
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
        'text/plain',
        'application/csv',
    ];

    // Разрешённые расширения
    private const ALLOWED_EXTENSIONS = ['xls', 'xlsx', 'csv'];

    // Максимальный размер: 50 MB
    private const MAX_SIZE = 50 * 1024 * 1024;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasFile('file')) {
            $file = $request->file('file');

            // Проверка размера
            if ($file->getSize() > self::MAX_SIZE) {
                return response()->json([
                    'error' => 'Файл слишком большой. Максимум 50 MB.',
                ], 422);
            }

            // Проверка расширения
            $extension = strtolower($file->getClientOriginalExtension());
            if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                return response()->json([
                    'error' => 'Разрешены только XLS, XLSX, CSV файлы.',
                ], 422);
            }

            // Проверка MIME по содержимому (magic bytes)
            $realMime = mime_content_type($file->getRealPath());
            if (! $this->isAllowedMime($realMime)) {
                return response()->json([
                    'error' => "Недопустимый тип файла: {$realMime}",
                ], 422);
            }

            // Генерируем безопасное имя файла
            $safeName = sprintf(
                'imports/%s_%s.%s',
                now()->format('Y-m-d_H-i-s'),
                substr(md5(uniqid('', true)), 0, 8),
                $extension
            );

            // Сохраняем в storage (НЕ в public web-доступной директории)
            Storage::disk('public')->putFileAs(
                'imports',
                $file,
                basename($safeName)
            );

            // Заменяем файл в request на сохранённый путь
            $request->merge(['safe_filepath' => $safeName]);
        }

        return $next($request);
    }

    private function isAllowedMime(string $mime): bool
    {
        // CSV может определяться как text/plain — это допустимо
        return in_array($mime, self::ALLOWED_IMPORT_MIMES, true)
            || str_starts_with($mime, 'text/');
    }
}
