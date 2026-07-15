<?php

namespace App\Models;

use App\Models\Traits\SeoMetaTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BlogPost extends Model
{
    use SeoMetaTrait;

    protected $guarded = [];

    protected $casts = [
        'faq' => 'array',
        'is_active' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'blog_post_product');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
