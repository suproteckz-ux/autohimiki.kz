<?php

namespace App\Services\ProductUrls;

use Illuminate\Support\Str;
use RuntimeException;

final class ProductSlugAllocator
{
    // Leaves space within the existing VARCHAR(255), including collision suffixes.
    public const MAX_LENGTH = 200;

    public static function technical(string $slug): bool
    {
        return preg_match('/^onec-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $slug) === 1;
    }

    public static function valid(string $slug): bool
    {
        return strlen($slug) <= 255 && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) === 1;
    }

    /** Reserve both ends of ALL redirects, including inactive and absolute URLs. */
    public function reserved(array $products, array $redirects): array
    {
        $reserved = [];
        foreach ($products as $product) {
            $reserved[strtolower($product->slug)] = true;
        }
        foreach ($redirects as $redirect) {
            foreach ([$redirect->from_url, $redirect->to_url] as $url) {
                $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
                if (preg_match('~^/product/([^/]+)/?$~i', $path, $m)) {
                    $reserved[strtolower($m[1])] = true;
                }
            }
        }

        return $reserved;
    }

    public function generate(string $name, array &$reserved): array
    {
        $base = trim(Str::lower(Str::slug($name)), '-');
        if ($base === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $base) !== 1) {
            throw new RuntimeException('name_has_no_route_compatible_slug');
        }
        for ($suffix = 0; $suffix < 100000; $suffix++) {
            $tail = $suffix === 0 ? '' : '-'.$suffix;
            $slug = rtrim(substr($base, 0, self::MAX_LENGTH - strlen($tail)), '-').$tail;
            if (! isset($reserved[$slug]) && ! str_starts_with($slug, 'onec-')) {
                $reserved[$slug] = true;

                return ['slug' => $slug, 'collision_status' => $suffix ? 'suffix_'.$suffix : 'none'];
            }
            if (str_starts_with($slug, 'onec-')) {
                throw new RuntimeException('name_generates_technical_prefix');
            }
        }
        throw new RuntimeException('slug_namespace_exhausted');
    }
}
