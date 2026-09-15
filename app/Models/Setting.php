<?php

namespace App\Models;

use App\Services\CacheService;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saved(static fn (Setting $setting) => CacheService::forgetSettings());
        static::deleted(static fn (Setting $setting) => CacheService::forgetSettings());
    }
}
