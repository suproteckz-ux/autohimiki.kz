@once
@php
    $phone = \App\Services\CacheService::setting('phone', '');
    $email = \App\Services\CacheService::setting('email', '');
    $address = \App\Services\CacheService::setting('address', '');
    $instagram = \App\Services\CacheService::setting('instagram', '');

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'Store',
        'name' => 'Autohimiki.kz',
        'description' => 'Auto chemistry and car care products in Almaty',
        'url' => url('/'),
        'address' => [
            '@type' => 'PostalAddress',
            'addressLocality' => 'Алматы',
            'addressRegion' => 'Алматы',
            'addressCountry' => 'KZ',
        ],
        'openingHoursSpecification' => [
            ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'opens' => '09:00', 'closes' => '18:00'],
            ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'Saturday', 'opens' => '11:00', 'closes' => '16:00'],
            ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'Sunday', 'opens' => '00:00', 'closes' => '00:00'],
        ],
        'priceRange' => 'KZT',
    ];

    if ($address) {
        $schema['address']['streetAddress'] = $address;
    }

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

<script type="application/ld+json">{!! \Illuminate\Support\Js::encode($schema) !!}</script>
@endonce
