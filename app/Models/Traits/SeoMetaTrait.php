<?php

namespace App\Models\Traits;

/**
 * SeoMetaTrait
 *
 * Предоставляет методы для получения SEO-полей с fallback-логикой.
 *
 * ИСПРАВЛЕНИЕ LA-3:
 * seoCanonical() использовал $this->getUrlAttribute() без проверки наличия метода.
 * Если модель не реализует getUrlAttribute() → Call to undefined method.
 * Добавлен безопасный fallback через method_exists() и url()->current().
 *
 * ПОЧЕМУ ЯВНЫЕ МЕТОДЫ, А НЕ ELOQUENT-АКСЕССОРЫ:
 * getMetaTitleAttribute() вызывается Eloquent при любом $model->meta_title,
 * что создаёт путаницу — непонятно вернёт БД-значение или шаблон.
 * Явные seoTitle() / seoDescription() / seoH1() читаемее и тестируемее.
 */
trait SeoMetaTrait
{
    /**
     * Meta Title: значение из БД или сгенерированный шаблон.
     */
    public function seoTitle(): string
    {
        $stored = $this->attributes['meta_title'] ?? null;

        return (! empty($stored))
            ? $stored
            : $this->defaultSeoTitle();
    }

    /**
     * Meta Description: значение из БД или сгенерированный шаблон.
     */
    public function seoDescription(): string
    {
        $stored = $this->attributes['meta_description'] ?? null;

        return (! empty($stored))
            ? $stored
            : $this->defaultSeoDescription();
    }

    /**
     * H1: значение из БД или название сущности.
     */
    public function seoH1(): string
    {
        $stored = $this->attributes['h1'] ?? null;

        return (! empty($stored))
            ? $stored
            : $this->defaultSeoH1();
    }

    /**
     * Canonical URL: значение из БД или авто-генерация.
     *
     * ИСПРАВЛЕНИЕ LA-3:
     * Старая версия вызывала $this->getUrlAttribute() без проверки.
     * Теперь:
     * 1. Проверяем getUrlAttribute() через method_exists()
     * 2. Fallback на url() из request контекста
     * 3. Последний fallback — config('app.url')
     */
    public function seoCanonical(): string
    {
        // Приоритет 1: явно заданный canonical в БД
        $stored = $this->attributes['canonical_url'] ?? null;
        if (! empty($stored)) {
            return $stored;
        }

        // Приоритет 2: url() из модели (getUrlAttribute)
        if (method_exists($this, 'getUrlAttribute')) {
            try {
                $url = $this->getUrlAttribute();
                if (! empty($url)) {
                    return $url;
                }
            } catch (\Throwable) {
                // Если метод упал (например, нет slug) — переходим к fallback
            }
        }

        // Приоритет 3: url свойство (если модель определяет $url)
        if (property_exists($this, 'url') && ! empty($this->url)) {
            return $this->url;
        }

        // Приоритет 4: текущий URL запроса (для использования в контроллере)
        // Безопасен только в HTTP-контексте
        if (app()->runningInConsole()) {
            return config('app.url', '');
        }

        return url()->current();
    }

    // ──────────────────────────────────────────────────────────────
    // Методы по умолчанию — переопределяются в каждой модели
    // ──────────────────────────────────────────────────────────────

    protected function defaultSeoTitle(): string
    {
        $name = $this->attributes['name'] ?? $this->attributes['title'] ?? '';
        return $name ? "{$name} | Autohimiki.kz" : 'Autohimiki.kz';
    }

    protected function defaultSeoDescription(): string
    {
        return $this->attributes['name'] ?? $this->attributes['title'] ?? '';
    }

    protected function defaultSeoH1(): string
    {
        return $this->attributes['name'] ?? $this->attributes['title'] ?? '';
    }
}
