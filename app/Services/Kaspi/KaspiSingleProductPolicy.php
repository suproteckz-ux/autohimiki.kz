<?php

namespace App\Services\Kaspi;

use Illuminate\Support\Facades\Storage;

class KaspiSingleProductPolicy
{
    // Original verified fixture; runtime validation is per candidate, never an allow-list.
    public const SKU = '00000000680';

    public const SLUG = 'foam-cleaner-mitsuji-universalnyy-pennyy-ochistitel';

    public const STOREFRONT = 'https://autohimiki.kz/product/'.self::SLUG;

    public const URL = 'https://kaspi.kz/shop/p/mitsuji-ochistitel-universal-nyi-mtz4002-0-65-l-142775620/';

    public static function assertSku(mixed $sku): void
    {
        if (! is_string($sku) || trim($sku) === '' || trim($sku) !== $sku || mb_strlen($sku) > 255 || preg_match('/[\x00-\x1f\x7f]/u', $sku)) {
            throw new \RuntimeException('invalid_exact_sku', 422);
        }
    }

    public static function mainImageExists(?string $path): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..') || preg_match('#^(?:/|[a-z]+:)|\\\\#i', $path)) {
            return false;
        }
        try {
            $disk = Storage::disk('public');

            return $disk->exists($path) && @getimagesize($disk->path($path)) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function imageUrl(string $url): bool
    {
        $p = parse_url($url);

        return is_array($p) && ($p['scheme'] ?? '') === 'https'
            && ($p['host'] ?? '') === 'resources.cdn-kaspi.kz'
            && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['port']) && ! isset($p['fragment'])
            && ! preg_match('/[\x00-\x20\\\\]/', $url)
            && ! in_array('..', explode('/', rawurldecode($p['path'] ?? '')), true)
            && (str_starts_with($p['path'] ?? '', '/img/m/p/') || str_starts_with($p['path'] ?? '', '/shop/medias/'));
    }

    public static function attributeKey(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }

    public static function description(?string $html): string
    {
        // Store only a small inert HTML vocabulary: no attributes, links, embedded media or script.
        if (trim((string) $html) === '') {
            return '';
        }
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $render = function ($node) use (&$render): string {
                if ($node instanceof \DOMText) {
                    return htmlspecialchars($node->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                if (! $node instanceof \DOMElement) {
                    return '';
                }
                $tag = strtolower($node->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'noscript', 'form'], true)) {
                    return '';
                }
                $text = '';
                foreach ($node->childNodes as $child) {
                    $text .= $render($child);
                }
                if ($tag === 'br') {
                    return '<br>';
                }

                return in_array($tag, ['p', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i'], true) ? '<'.$tag.'>'.$text.'</'.$tag.'>' : $text;
            };
            $result = $render($dom->documentElement);

            return trim(strip_tags($result)) === '' ? '' : trim($result);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
