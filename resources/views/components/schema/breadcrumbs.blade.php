@props(['items' => []])

@php
    $crumbs = array_values(array_filter(
        array_merge([['name' => 'Главная', 'url' => url('/')]], $items),
        fn ($crumb) => ! empty($crumb['name'])
    ));

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => collect($crumbs)
            ->values()
            ->map(fn ($crumb, $index) => array_filter([
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'] ?? null,
            ]))
            ->all(),
    ];
@endphp

@if(count($crumbs) > 1)
<script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) !!}
</script>
@endif
