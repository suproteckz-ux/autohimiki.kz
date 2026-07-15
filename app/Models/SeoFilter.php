<?php

namespace App\Models;

use App\Models\Traits\SeoMetaTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoFilter extends Model
{
    use SeoMetaTrait;

    protected $fillable = [
        'name',
        'slug',
        'category_id',
        'brand_id',
        'h1',
        'meta_title',
        'meta_description',
        'seo_text',
        'canonical_url',
        'faq',
        'is_indexed',
        'is_active',
    ];

    protected $casts = [
        'faq' => 'array',
        'is_indexed' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeIndexed(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('is_indexed', true);
    }
}
