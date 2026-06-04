<?php

namespace App\Models;

use App\Models\Traits\SeoMetaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;

class Category extends Model
{
    use SeoMetaTrait;

    protected $fillable = [
        'parent_id', 'name', 'slug',
        'image', 'image_webp',
        'meta_title', 'meta_description', 'h1',
        'seo_text_top', 'seo_text_bottom', 'canonical_url',
        'faq', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'faq'       => 'array',
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
        return "Купить {$name} в Алматы. Автохимия и товары для ухода за авто. "
             . "Консультация, самовывоз и доставка по Казахстану.";
    }

    protected function defaultSeoH1(): string
    {
        return $this->attributes['name'] ?? '';
    }

    // ──────────────────────────────────────────────────────────────
    // Связи
    // ──────────────────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')
                    ->orderBy('sort_order');
    }

    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function seoPages(): BelongsToMany
    {
        return $this->belongsToMany(SeoPage::class, 'seo_page_category');
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

    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // ──────────────────────────────────────────────────────────────
    // Вспомогательные методы
    // ──────────────────────────────────────────────────────────────

    public function isRoot(): bool
    {
        return is_null($this->parent_id);
    }

    public function getUrlAttribute(): string
    {
        if ($this->parent) {
            return route('catalog.subcategory', [
                'parent' => $this->parent->slug,
                'child'  => $this->slug,
            ]);
        }
        return route('catalog.category', ['category' => $this->slug]);
    }
}
