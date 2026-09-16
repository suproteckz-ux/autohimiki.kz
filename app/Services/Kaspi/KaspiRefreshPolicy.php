<?php

namespace App\Services\Kaspi;

use Illuminate\Support\Facades\DB;

/** Deliberately closed lists: never infer ownership from a spelling/prefix/value. */
class KaspiRefreshPolicy
{
    // The characteristic names explicitly mapped by the existing Kaspi parser.
    public const CONTENT_KEYS = ['тип', 'тип полировки', 'назначение', 'объем упаковки', 'объем',
        'аэрозоль', 'спрей', 'особенности', 'дополнительная информация', 'цвет'];

    // Product columns / commercial exclusions and exact parser service metadata names.
    public const PROTECTED_KEYS = ['id', 'sku', 'name', 'slug', 'canonical_url', 'category', 'category_id', 'brand_id',
        'price', 'old_price', 'quantity', 'in_stock', 'stock', 'availability', 'is_active', 'published',
        'is_new', 'is_hit', 'is_popular', 'meta_title', 'meta_description', 'meta_keywords', 'h1', 'seo_text',
        'short_description', 'usage_instructions', 'faq', 'main_image', 'main_image_webp', 'main_image_alt',
        'views', 'sort_order', 'created_at', 'updated_at', 'description', 'attributes',
        'цена', 'остаток', 'остатки', 'createdtime', 'shoplink', 'categoryid', 'reviewslink', 'code', 'type',
        'measurementliteral', 'countingliteral', 'small', 'medium', 'large', 'location', 'endpoint', 'link',
        'subtitle', 'region', 'regionid', 'currency', 'environment', 'version', 'url', 'image', 'value', 'title'];

    public const REASONS = ['identity_changed', 'state_changed', 'no_images', 'empty_description', 'empty_attributes',
        'attributes_ambiguous', 'attributes_invalid', 'image_limit_exceeded', 'attribute_limit_exceeded',
        'image_download_failed', 'image_too_large', 'image_http_failed', 'image_invalid_mime', 'image_dns_rejected',
        'image_url_not_allowed', 'image_storage_write_failed', 'image_storage_collision', 'payload_too_large',
        'validation_failed', 'invalid_payload', 'invalid_force_flag', 'payload_identity_mismatch',
        'commercial_attribute_not_allowed', 'import_locked', 'import_failed', 'image_cleanup_failed',
        'invalid_preview_response', 'import_transport_failed_check_before_retry', 'invalid_import_response_check_before_retry'];

    public static function force(array $data): bool
    {
        if (array_key_exists('force_content_refresh', $data) && ! is_bool($data['force_content_refresh'])) {
            throw new \RuntimeException('invalid_force_flag', 422);
        }

        return $data['force_content_refresh'] ?? false;
    }

    /** Query strings have no boolean type. Accept only explicit canonical encodings. */
    public static function queryForce(array $data): bool
    {
        if (! array_key_exists('force_content_refresh', $data)) {
            return false;
        }

        return match ($data['force_content_refresh']) {
            'true', '1', true => true,
            'false', '0', false => false,
            default => throw new \RuntimeException('invalid_force_flag', 422),
        };
    }

    public static function existing(?string $json): array
    {
        if ($json === null || trim($json) === '' || trim($json) === 'null') {
            return [];
        }
        $object = json_decode($json);
        if (! $object instanceof \stdClass) {
            throw new \RuntimeException('attributes_ambiguous', 422);
        }
        $preserved = [];
        $seen = [];
        foreach (get_object_vars($object) as $name => $value) {
            $key = KaspiSingleProductPolicy::attributeKey((string) $name);
            if (isset($seen[$key])) {
                throw new \RuntimeException('attributes_ambiguous', 422);
            }
            $seen[$key] = true;
            if (in_array($key, self::PROTECTED_KEYS, true)) {
                $preserved[$name] = $value;
            } elseif (! in_array($key, self::CONTENT_KEYS, true) || ! is_scalar($value)) {
                throw new \RuntimeException('attributes_ambiguous', 422);
            }
        }

        return $preserved;
    }

    public static function incoming(array $attributes): void
    {
        if ($attributes === []) {
            throw new \RuntimeException('empty_attributes', 422);
        }
        foreach ($attributes as $attribute) {
            if (! in_array(KaspiSingleProductPolicy::attributeKey($attribute['name']), self::CONTENT_KEYS, true)) {
                throw new \RuntimeException('attributes_ambiguous', 422);
            }
        }
    }

    public static function canonical(mixed $value): string
    {
        $sort = function ($value) use (&$sort) {
            if (is_array($value)) {
                if (! array_is_list($value)) {
                    ksort($value, SORT_STRING);
                }

                return array_map($sort, $value);
            }

            return $value;
        };

        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function state(object $product, ?array $gallery = null): array
    {
        $gallery ??= DB::table('product_images')->where('product_id', $product->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        // All product columns, not updated_at alone; includes protected fields and exact stored JSON.
        $fingerprint = hash('sha256', self::canonical(['product' => (array) $product, 'gallery' => $gallery,
            'merchant' => (string) config('services.kaspi.merchant_id'), 'city' => (string) config('services.kaspi.city_id')]));
        $paths = array_filter([$product->main_image, ...array_column($gallery, 'path')], fn ($p) => is_string($p) && trim($p) !== '');
        $attributes = json_decode((string) $product->attributes, true);

        return ['product_id' => (int) $product->id, 'state_fingerprint' => $fingerprint,
            'current_photo_count' => count(array_unique($paths)), 'current_description_present' => trim((string) $product->description) !== '',
            'current_attribute_count' => is_array($attributes) ? count($attributes) : 0];
    }

    public static function approval(array $payloads): string
    {
        usort($payloads, fn ($a, $b) => $a['product_id'] <=> $b['product_id']);

        return hash('sha256', self::canonical(['policy' => 1, 'ready' => $payloads]));
    }
}
