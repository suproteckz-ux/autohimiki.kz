<?php

namespace App\Services\Ozon;

use App\Models\OzonCategoryMapping;
use App\Models\Product;

class OzonPayload
{
    public function quantity(Product $product): int
    {
        return max(0, (int) $product->quantity);
    }

    public function description(Product $product): string
    {
        $lines = [];
        foreach ($product->getAttribute('attributes') ?? [] as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $lines[] = $key.': '.$value;
            } elseif (is_array($value) && isset($value['name'], $value['value']) && is_scalar($value['value'])) {
                $lines[] = $value['name'].': '.$value['value'];
            }
        }

        return trim((string) $product->description).($lines ? "\n\nХарактеристики:\n".implode("\n", $lines) : '');
    }

    public function images(Product $product): array
    {
        $base = rtrim((string) config('app.url'), '/');
        if (parse_url($base, PHP_URL_SCHEME) !== 'https') {
            return [];
        }
        $urls = [];
        foreach ([$product->main_image, ...$product->images->pluck('path')->all()] as $path) {
            if (! is_string($path) || trim($path) === '') {
                continue;
            }
            if (preg_match('~^https?://~i', $path)) {
                if (parse_url($path, PHP_URL_SCHEME) !== 'https' || parse_url($path, PHP_URL_HOST) !== parse_url($base, PHP_URL_HOST)) {
                    continue;
                }
                $path = parse_url($path, PHP_URL_PATH);
            }
            $path = rawurldecode(ltrim($path, '/'));
            if (str_contains($path, '..') || str_contains($path, ':') || str_contains($path, '\\') || preg_match('/[\x00-\x1f]/', $path)) {
                continue;
            }
            $path = preg_replace('~^storage/~', '', $path);
            $urls[] = $base.'/storage/'.implode('/', array_map('rawurlencode', explode('/', $path)));
        }

        return array_values(array_unique($urls));
    }

    public function create(Product $product, OzonCategoryMapping $mapping): array
    {
        if (trim((string) $product->sku) === '' || trim((string) $product->name) === '' || (float) $product->price <= 0) {
            throw new \RuntimeException('missing_sku_name_or_price');
        }
        if (mb_strlen($product->sku) > 50) {
            throw new \RuntimeException('offer_id_too_long');
        }
        $images = $this->images($product);
        $description = $this->description($product);

        return [
            'offer_id' => $product->sku,
            'name' => mb_substr(trim($product->name), 0, 500),
            'description_category_id' => (int) $mapping->ozon_description_category_id,
            'type_id' => (int) $mapping->ozon_type_id,
            'price' => (string) $product->price,
            'currency_code' => config('ozon.currency'),
            'vat' => config('ozon.vat'),
            'primary_image' => $images[0] ?? '',
            'images' => $images,
            'attributes' => $description === '' ? [] : [
                ['id' => 4191, 'complex_id' => 0, 'values' => [['value' => $description]]],
            ],
        ];
    }
}
