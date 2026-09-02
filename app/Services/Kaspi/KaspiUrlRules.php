<?php

namespace App\Services\Kaspi;

class KaspiUrlRules
{
    public static function base(): string
    {
        $base = rtrim((string) config('services.kaspi.production_base_url'), '/');
        $parts = parse_url($base);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['port'])
            || ! empty($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('invalid_production_base_url');
        }

        return $base;
    }

    public static function storefront(string $url): bool
    {
        $parts = parse_url($url);
        $base = parse_url(self::base());

        return $parts && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === $base['host']
            && ! isset($parts['user']) && ! isset($parts['port']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && preg_match('~^/product/[^/]+$~D', $parts['path'] ?? '') === 1;
    }

    public static function product(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port'])
            || ($host !== 'kaspi.kz' && ! str_ends_with($host, '.kaspi.kz'))
            || ! preg_match('~^/shop/p/[^/]+-[0-9]+/?$~D', $parts['path'] ?? '')) {
            return null;
        }

        return 'https://'.$host.rtrim($parts['path'], '/').'/';
    }
}
