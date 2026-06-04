<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportColumnTemplate extends Model
{
    protected $fillable = ['name', 'type', 'column_map', 'is_default'];

    protected $casts = [
        'column_map' => 'array',
        'is_default' => 'boolean',
    ];

    /**
     * Получить шаблон по умолчанию для типа импорта.
     */
    public static function getDefault(string $type): ?self
    {
        return static::where('type', $type)
            ->where('is_default', true)
            ->first();
    }
}
