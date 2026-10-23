<?php

namespace App\Services\SeoManager;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

class FilterProducts
{
    public function query(array $criteria): Builder
    {
        $query = Product::active()->inStock();
        if (! empty($criteria['category_id'])) {
            $query->where('category_id', $criteria['category_id']);
        }
        if (! empty($criteria['brand_id'])) {
            $query->where('brand_id', $criteria['brand_id']);
        }
        foreach ($criteria['attributes'] ?? [] as $key => $value) {
            // These paths are validated on API intake; also fail closed for admin-edited DB data.
            if (! preg_match('/^[\pL\pN _-]{1,80}$/u', (string) $key) || ! is_scalar($value)) {
                return $query->whereRaw('1 = 0');
            }
            $query->where('attributes->'.$key, $value);
        }

        return $query;
    }
}
