<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RemoveTrailingSlash
 *
 * Перенаправляет URL с завершающим слешем на URL без него.
 *
 * Примеры:
 *   /catalog/avtoshampuni/  → 301 → /catalog/avtoshampuni
 *   /product/test-product/  → 301 → /product/test-product
 *
 * Исключения:
 *   / (корень сайта) — не трогаем
 *   /admin/ — Filament сам управляет своими маршрутами
 */
class RemoveTrailingSlash
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->getPathInfo();

        // Пропускаем корень и маршруты Filament
        if ($path === '/' || str_starts_with($path, '/admin')) {
            return $next($request);
        }

        // Если путь заканчивается на / — редиректим 301
        if (str_ends_with($path, '/')) {
            $cleanPath = rtrim($path, '/');
            $query     = $request->getQueryString();
            $target    = $cleanPath . ($query ? '?' . $query : '');

            return redirect($target, 301);
        }

        return $next($request);
    }
}
