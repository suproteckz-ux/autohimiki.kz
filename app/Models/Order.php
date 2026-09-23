<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const STATUSES = ['new' => 'Новый', 'confirmed' => 'Подтверждён', 'completed' => 'Выполнен', 'cancelled' => 'Отменён'];

    public const DELIVERY = ['pickup' => 'Самовывоз', 'yandex' => 'Яндекс Доставка по Алматы', 'kazpost' => 'Казпочта / доставка в другие города'];

    protected $guarded = ['id'];

    protected $hidden = ['checkout_token'];

    protected $casts = ['total' => 'decimal:2', 'consented_at' => 'datetime'];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
