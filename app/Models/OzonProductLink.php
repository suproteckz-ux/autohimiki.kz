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
    ];
}
