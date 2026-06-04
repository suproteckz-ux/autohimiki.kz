<?php

namespace App\Models;

use App\Models\Traits\SeoMetaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Brand extends Model
{
    use SeoMetaTrait;

    protected $fillable = [
        'name', 'slug', 'logo', 'logo_webp', 'description',
        'meta_title', 'meta_description', 'h1', 'canonical_url',
        'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ──────────────────────────────────────────────────────────────
    // SEO-шаблоны (SeoMetaTrait)
    // ──────────────────────────────────────────────────────────────

    protected function defaultSeoTitle(): string
    {
        $name = $this->attributes['name'] ?? '';
        return "{$name} купить в Алматы | Autohimiki.kz";
    }

    protected function defaultSeoDescription(): string
    {
        $name = $this->attributes['name'] ?? '';
        return "Товары {$name} в Алматы. Автохимия, автокосметика и аксессуары "
             . "для ухода за автомобилем. Консультация и доставка.";
    }

    protected function defaultSeoH1(): string
    {
        $name = $this->attributes['name'] ?? '';
        return "Автохимия {$name}";
    }

    // ──────────────────────────────────────────────────────────────
    // Связи
    // ──────────────────────────────────────────────────────────────

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function seoFilters(): HasMany
    {
        return $this->hasMany(SeoFilter::class);
    }

    // ──────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // ──────────────────────────────────────────────────────────────
    // Вспомогательные методы
    // ──────────────────────────────────────────────────────────────

    public function getUrlAttribute(): string
    {
        return route('brand.show', ['brand' => $this->slug]);
    }
}
