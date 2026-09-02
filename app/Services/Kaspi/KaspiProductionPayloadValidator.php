<?php

namespace App\Services\Kaspi;

use Illuminate\Support\Facades\Validator;

class KaspiProductionPayloadValidator
{
    public function validate(array $payload, int $bytes = 0): array
    {
        KaspiSingleProductPolicy::assertSku($payload['sku'] ?? null);
        if ($bytes > 131072 || array_diff(array_keys($payload), ['version', 'sku', 'storefront_url', 'kaspi_url', 'source', 'content'])) {
            throw new \RuntimeException('invalid_payload', 422);
        }
        $data = Validator::make($payload, [
            'version' => ['required', 'integer', 'in:1'],
            'sku' => ['required', 'string'],
            'storefront_url' => ['required', 'string'],
            'kaspi_url' => ['required', 'string'],
            'source' => ['required', 'array:collector,resolver_verified,merchant_id,city_id,captcha'],
            'source.collector' => ['required', 'in:local-playwright'],
            'source.resolver_verified' => ['required', 'accepted'],
            'source.merchant_id' => ['required', 'string'],
            'source.city_id' => ['required', 'string'],
            'source.captcha' => ['required', 'boolean'],
            'content' => ['required', 'array:title,description,images,attributes'],
            'content.title' => ['required', 'string', 'max:255'],
            'content.description' => ['present', 'nullable', 'string', 'max:65000'],
            'content.attributes' => ['sometimes', 'array', 'list', 'max:80'],
            'content.attributes.*' => ['array:name,value'],
            'content.attributes.*.name' => ['present', 'nullable', 'string', 'max:120'],
            'content.attributes.*.value' => ['present', 'nullable', 'string', 'max:1000'],
            'content.images' => ['required', 'array', 'list', 'min:1', 'max:12'],
            'content.images.*' => ['required', 'string', 'max:2048', 'distinct:strict'],
        ])->validate();
        if (($data['version'] !== 1) || $data['storefront_url'] !== KaspiSingleProductPolicy::STOREFRONT
            || $data['kaspi_url'] !== KaspiSingleProductPolicy::URL
            || $data['source']['resolver_verified'] !== true || $data['source']['captcha'] !== false
            || trim((string) config('services.kaspi.merchant_id')) === ''
            || trim((string) config('services.kaspi.city_id')) === ''
            || $data['source']['merchant_id'] !== (string) config('services.kaspi.merchant_id')
            || $data['source']['city_id'] !== (string) config('services.kaspi.city_id')) {
            throw new \RuntimeException('payload_identity_mismatch', 422);
        }
        foreach ($data['content']['images'] as $url) {
            if (! KaspiSingleProductPolicy::imageUrl($url)) {
                throw new \RuntimeException('image_url_not_allowed', 422);
            }
        }
        $attributes = [];
        $seen = [];
        foreach ($data['content']['attributes'] ?? [] as $attribute) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) $attribute['name']) ?? '');
            $value = trim((string) $attribute['value']);
            $key = KaspiSingleProductPolicy::attributeKey($name);
            if (in_array($key, ['price', 'old_price', 'quantity', 'in_stock', 'stock', 'availability', 'sku', 'slug', 'name',
                'category', 'category_id', 'is_active', 'published', 'meta_title', 'meta_description', 'цена', 'остаток', 'остатки'], true)) {
                throw new \RuntimeException('commercial_attribute_not_allowed', 422);
            }
            if ($key === '' || $value === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $attributes[] = ['name' => $name, 'value' => $value];
        }
        $data['content']['attributes'] = $attributes;
        $data['content']['description'] = KaspiSingleProductPolicy::description($data['content']['description']);

        return $data;
    }
}
