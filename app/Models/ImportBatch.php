<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    protected $fillable = [
        'type',
        'filename',
        'filepath',
        'column_map',
        'price_changes',
        'stock_changes',
        'total_rows',
        'created_count',
        'updated_count',
        'skipped_count',
        'error_count',
        'not_found_count',
        'status',
        'user_id',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'column_map'    => 'array',
        'price_changes' => 'array',
        'stock_changes' => 'array',
        'started_at'    => 'datetime',
        'finished_at'   => 'datetime',
    ];

    // ──────────────────────────────────────────────────────────────
    // Связи
    // ──────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function errors(): HasMany
    {
        return $this->hasMany(ImportError::class);
    }

    // ──────────────────────────────────────────────────────────────
    // Вспомогательные методы
    // ──────────────────────────────────────────────────────────────

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            'prices_only' => 'Обновление из 1С',
            'full'        => 'Полный импорт',
            default       => $this->type,
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'pending'    => 'Ожидает',
            'processing' => 'Обрабатывается',
            'done'       => 'Завершён',
            'failed'     => 'Ошибка',
            default      => $this->status,
        };
    }

    public function getDurationAttribute(): ?string
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }
        $seconds = $this->started_at->diffInSeconds($this->finished_at);
        return $seconds < 60
            ? "{$seconds} сек"
            : round($seconds / 60, 1) . ' мин';
    }

    /**
     * Ключ кэша для отслеживания прогресса.
     */
    public function progressCacheKey(): string
    {
        return "import_progress_{$this->id}";
    }

    /**
     * Атомарно инкрементировать счётчики (без состояния гонки).
     */
    public function incrementCounter(string $field, int $by = 1): void
    {
        static::where('id', $this->id)->increment($field, $by);
    }
}
