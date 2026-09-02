<?php

namespace App\Services\Kaspi;

use Illuminate\Support\Facades\DB;

class KaspiProductionCandidateService
{
    public function list(array $options = []): array
    {
        $limit = min(100, max(1, (int) ($options['limit'] ?? 25)));
        $cursor = (int) ($options['cursor'] ?? 0);
        $sku = isset($options['sku']) ? trim($options['sku']) : null;
        $query = DB::table('products')->select(['id', 'sku', 'slug', 'name', 'main_image', 'description', 'attributes'])
            ->selectSub(DB::table('product_images')->selectRaw('COUNT(*)')->whereColumn('product_id', 'products.id'), 'gallery_count')
            ->where('is_active', true)->where('id', '>', $cursor)
            ->whereNotNull('sku')->whereNotNull('slug');
        if ($sku !== null) {
            $query->where('sku', $sku);
        }
        $data = [];
        $lastId = null;
        // Bounded chunks also allow exact PHP comparison on case-insensitive DB collations.
        foreach ($query->lazyById(100) as $product) {
            if (trim($product->sku) === '' || trim($product->slug) === '' || str_contains($product->slug, '/')
                || ($sku !== null && $product->sku !== $sku)) {
                continue;
            }
            $hasImages = trim((string) $product->main_image) !== '';
            $hasDescription = trim((string) $product->description) !== '';
            $fileExists = KaspiSingleProductPolicy::mainImageExists($product->main_image);
            $attributes = json_decode((string) $product->attributes, true);
            $hasAttributes = is_array($attributes) && $attributes !== [];
            // An explicit exact SKU is a diagnostic override of content completeness only.
            if ($sku === null && $hasImages && $fileExists && $hasDescription && $hasAttributes && (int) $product->gallery_count > 0) {
                continue;
            }
            if (count($data) === $limit) {
                return ['data' => $data, 'next_cursor' => $lastId];
            }
            $data[] = ['sku' => $product->sku, 'name' => $product->name,
                'storefront_url' => KaspiUrlRules::base().'/product/'.rawurlencode($product->slug),
                'has_images' => $hasImages, 'has_main_image' => $hasImages,
                'gallery_count' => (int) $product->gallery_count, 'has_description' => $hasDescription, 'has_attributes' => $hasAttributes, 'main_image_file_exists' => $fileExists];
            $lastId = (int) $product->id;
        }

        return ['data' => $data, 'next_cursor' => null];
    }
}
