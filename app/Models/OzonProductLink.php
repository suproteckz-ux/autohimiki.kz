<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OzonProductLink extends Model
{
    protected $guarded = [];

    protected $casts = [
        'create_attempted_at' => 'datetime', 'exported_at' => 'datetime',
        'last_content_sync_at' => 'datetime', 'last_stock_sync_at' => 'datetime',
        'publication_confirmed_at' => 'datetime',
        'last_status_check_at' => 'datetime',
    ];

    public static function statusLabels(): array
    {
        return ['pending' => 'Отправляется', 'exported' => 'Задача принята',
            'processing' => 'Обрабатывается Ozon', 'requires_manual_review' => 'Требует ручной доработки',
            'ready' => 'Карточка создана', 'error' => 'Ошибка Ozon', 'published' => 'Опубликован'];
    }
}
