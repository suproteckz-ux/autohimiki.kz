<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SecurityHeadersMiddleware
 *
 * ИСПРАВЛЕНИЕ SEC-2:
 * Filament использует Alpine.js, Livewire и inline-скрипты.
 * Жёсткий CSP с script-src 'self' сломал бы /admin.
 *
 * Стратегия:
 * - Публичная часть (/):    строгий CSP
 * - Административная (/admin): только базовые заголовки, без CSP
 *
 * Проверить что CSP НЕ применяется к /admin:
 *   curl -sI https://autohimiki.kz/admin | grep Content-Security
 *   # Должно быть пустым
 *
 *   curl -sI https://autohimiki.kz/ | grep Content-Security
 *   # Должен быть CSP заголовок
 */
class SecurityHeadersMiddleware
{
    /**
     * Заголовки применяемые ко всем ответам (включая /admin).
     */
    private const UNIVERSAL_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'SAMEORIGIN',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=(self), payment=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Не добавляем заголовки к бинарным ответам
        if ($this->isBinaryResponse($response)) {
            return $response;
        }

        // ── Универсальные заголовки (все страницы) ────────────────
        foreach (self::UNIVERSAL_HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        // HSTS только для HTTPS
        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        // ── CSP: только для публичной части (НЕ для /admin) ──────
        // ИСПРАВЛЕНИЕ SEC-2: Filament 4 использует inline-скрипты Livewire/Alpine
        // Строгий CSP ломает Filament — исключаем /admin полностью
        if (! $this->isAdminRequest($request)) {
            $response->headers->set(
                'Content-Security-Policy',
                $this->buildPublicCsp()
            );
        }
        // Для /admin CSP НЕ устанавливаем — браузер использует дефолтный (разрешительный)

        return $response;
    }

    /**
     * Проверяет что запрос идёт к административной панели.
     */
    private function isAdminRequest(Request $request): bool
    {
        return $request->is('admin')
            || $request->is('admin/*')
            || $request->is('livewire/*');  // Livewire polling из /admin
    }

    /**
     * CSP для публичной части сайта.
     *
     * Разрешаем:
     * - Скрипты: self + аналитика Google/Яндекс/Facebook
     * - Стили: self + Google Fonts
     * - Изображения: self + data: (inline) + https: (любые CDN)
     * - Фреймы: запрещены (frame-src 'none')
     */
    private function buildPublicCsp(): string
    {
        $directives = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' "
                . "https://www.googletagmanager.com "
                . "https://www.google-analytics.com "
                . "https://connect.facebook.net "
                . "https://mc.yandex.ru",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: https: blob:",
            "connect-src 'self' "
                . "https://www.google-analytics.com "
                . "https://mc.yandex.ru "
                . "https://api.whatsapp.com",
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self' https://wa.me",  // форма может вести на WhatsApp
        ];

        return implode('; ', $directives);
    }

    private function isBinaryResponse(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type', '');
        return str_contains($contentType, 'image/')
            || str_contains($contentType, 'application/octet-stream')
            || str_contains($contentType, 'application/pdf');
    }
}
