<?php

namespace App\Models;

use App\Models\Traits\SeoMetaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Builder;

class Product extends Model
{
    use SeoMetaTrait;

    protected $fillable = [
        'category_id', 'brand_id',
        'name', 'slug', 'sku',
        'price', 'old_price',
        'in_stock', 'quantity',
        'short_description', 'description', 'usage_instructions',
        'attributes', 'faq',
        'main_image', 'main_image_webp', 'main_image_alt',
        'meta_title', 'meta_description', 'h1', 'seo_text', 'canonical_url',
        'is_active', 'is_new', 'is_hit', 'is_popular',
        'views', 'sort_order',
    ];

    protected $casts = [
        'attributes' => 'array',
        'faq'        => 'array',
        'price'      => 'decimal:2',
        'old_price'  => 'decimal:2',
        'in_stock'   => 'boolean',
        'is_active'  => 'boolean',
        'is_new'     => 'boolean',
        'is_hit'     => 'boolean',
        'is_popular' => 'boolean',
    ];

    // ──────────────────────────────────────────────────────────────
    // SEO (через SeoMetaTrait)
    // Переопределяем шаблоны для товаров
    // ──────────────────────────────────────────────────────────────

    protected function defaultSeoTitle(): string
    {
        $name = $this->attributes['name'] ?? '';
        return "{$name} купить в Алматы | Autohimiki.kz";
    }

    protected function defaultSeoDescription(): string
    {
        $name  = $this->attributes['name'] ?? '';
        $price = number_format((float) ($this->attributes['price'] ?? 0), 0, '.', ' ');
        return "Купить {$name} в Алматы. Цена {$price} тг. Наличие, консультация. Доставка по Казахстану.";
    }

    protected function defaultSeoH1(): string
    {
        return $this->attributes['name'] ?? '';
    }

    // ──────────────────────────────────────────────────────────────
    // Связи
    // ──────────────────────────────────────────────────────────────

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function seoPages(): BelongsToMany
    {
        return $this->belongsToMany(SeoPage::class, 'seo_page_product');
    }

    public function blogPosts(): BelongsToMany
    {
        return $this->belongsToMany(BlogPost::class, 'blog_post_product');
    }

    // ──────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('in_stock', true);
    }

    public function scopeHits(Builder $query): Builder
    {
        return $query->where('is_hit', true)->where('is_active', true);
    }

    public function scopeNew(Builder $query): Builder
    {
        return $query->where('is_new', true)->where('is_active', true);
    }

    public function scopePopular(Builder $query): Builder
    {
        return $query->where('is_popular', true)->where('is_active', true);
    }

    public function scopeInCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeByBrand(Builder $query, int $brandId): Builder
    {
        return $query->where('brand_id', $brandId);
    }

    public function scopePriceBetween(Builder $query, ?float $min, ?float $max): Builder
    {
        if ($min !== null) {
            $query->where('price', '>=', $min);
        }
        if ($max !== null) {
            $query->where('price', '<=', $max);
        }
        return $query;
    }

    // ──────────────────────────────────────────────────────────────
    // Вспомогательные методы
    // ──────────────────────────────────────────────────────────────

    public function getUrlAttribute(): string
    {
        return route('product.show', ['product' => $this->slug]);
    }

    public function hasDiscount(): bool
    {
        return $this->old_price && $this->old_price > $this->price;
    }

    public function getDiscountPercentAttribute(): int
    {
        if (! $this->hasDiscount()) {
            return 0;
        }
        return (int) round((1 - $this->price / $this->old_price) * 100);
    }

    /**
     * Сообщение для WhatsApp с именем товара.
     * Используется в карточке и на странице товара.
     */
    public function getWhatsappMessageAttribute(): string
    {
        return urlencode(
            "Здравствуйте! Хочу купить {$this->name} с сайта autohimiki.kz"
        );
    }

    /**
     * Инкремент просмотров без race condition.
     */
    public function incrementViews(): void
    {
        static::where('id', $this->id)->increment('views');
    }
}
