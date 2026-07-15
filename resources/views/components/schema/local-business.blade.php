@once
@php
    $phone = \App\Services\CacheService::setting('phone', '');
    $email = \App\Services\CacheService::setting('email', '');
    $address = \App\Services\CacheService::setting('address', 'Almaty');
    $instagram = \App\Services\CacheService::setting('instagram', '');

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'Store',
        'name' => 'Autohimiki.kz',
        'description' => 'Auto chemistry and car care products in Almaty',
        'url' => url('/'),
        'address' => [
            '@type' => 'PostalAddress',
            'streetAddress' => $address,
            'addressLocality' => 'Almaty',
            'addressRegion' => 'Almaty',
            'addressCountry' => 'KZ',
        ],
        'openingHours' => [
            'Mo-Fr 09:00-19:00',
            'Sa 10:00-17:00',
        ],
        'priceRange' => 'KZT',
    ];

    if ($phone) {
        $schema['telephone'] = $phone;
    }

    if ($email) {
        $schema['email'] = $email;
    }

    if ($instagram) {
        $schema['sameAs'] = [$instagram];
    }
@endphp

<script type="application/ld+json">{!! \Illuminate\Support\Js::from($schema) !!}</script>
@endonce
