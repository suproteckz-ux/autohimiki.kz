<?php

namespace App\Services\Ozon;

use App\Models\OzonCategoryMapping;
use App\Models\Setting;

class OzonAdminSettings
{
    public const CATEGORY = 'Очистители салона';

    // Valid values mirror what the Ozon v3/product/import API accepts for the `vat` field
    // (decimal string: '0' = 0%, '0.1' = 10%, '0.16' = 16%, '0.2' = 20%).
    public const VAT_RATES = [
        '0'    => '0%',
        '0.1'  => '10%',
        '0.16' => '16% (Казахстан)',
        '0.2'  => '20%',
    ];

    public function vat(): ?string
    {
        return $this->read()['vat'] ?? null;
    }

    public function saveVat(string $vat): void
    {
        if (! array_key_exists($vat, self::VAT_RATES)) {
            throw new \RuntimeException('ozon_vat_invalid');
        }
        $this->merge(['vat' => $vat]);
    }

    public function read(): array
    {
        return json_decode(Setting::where('key', 'ozon_admin')->value('value') ?? '{}', true) ?: [];
    }

    public function saveCategory(int $category, int $type): void
    {
        if ($category <= 0 || $type <= 0) {
            throw new \RuntimeException('ozon_global_category_missing');
        }
        $this->merge(['description_category_id' => $category, 'type_id' => $type]);
    }

    public function mapping(): OzonCategoryMapping
    {
        $settings = $this->read();
        if (empty($settings['description_category_id']) || empty($settings['type_id'])) {
            throw new \RuntimeException('ozon_global_category_missing: заполните category/type на странице Ozon');
        }

        // A transient mapping preserves the existing exporter contract; never tied to a local category.
        return new OzonCategoryMapping(['ozon_description_category_id' => $settings['description_category_id'],
            'ozon_type_id' => $settings['type_id'], 'enabled' => true]);
    }

    public function connection(bool $ok, string $message, ?string $seller = null): void
    {
        $safe = app(OzonConnectionResponsePreview::class);
        $this->merge(['connection_ok' => $ok, 'connection_message' => $safe->message($message),
            'checked_at' => now()->toIso8601String(), 'seller' => $safe->message($seller ?? '')]);
    }

    private function merge(array $values): void
    {
        Setting::updateOrCreate(['key' => 'ozon_admin'], ['value' => json_encode(array_merge($this->read(), $values), JSON_UNESCAPED_UNICODE)]);
    }
}
