@once
@php
    $phone    = \App\Models\Setting::get('phone', '');
    $email    = \App\Models\Setting::get('email', '');
    $address  = \App\Models\Setting::get('address', 'Алматы');
    $schedule = \App\Models\Setting::get('schedule', 'Mo-Fr 09:00-19:00');
    $instagram = \App\Models\Setting::get('instagram', '');
@endphp

<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "Store",
    "name": "Autohimiki.kz",
    "description": "Автохимия и товары для ухода за автомобилем в Алматы",
    "url": "{{ url('/') }}",
    @if($phone)
    "telephone": "{{ $phone }}",
    @endif
    @if($email)
    "email": "{{ $email }}",
    @endif
    "address": {
        "@type": "PostalAddress",
        "streetAddress": "{{ $address }}",
        "addressLocality": "Алматы",
        "addressRegion": "Алматы",
        "addressCountry": "KZ"
    },
    "openingHours": [
        "Mo-Fr 09:00-19:00",
        "Sa 10:00-17:00"
    ],
    "priceRange": "₸₸"
    @if($instagram)
    , "sameAs": ["{{ $instagram }}"]
    @endif
}
</script>
@endonce
