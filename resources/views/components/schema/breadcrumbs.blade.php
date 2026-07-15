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
{!! \Illuminate\Support\Js::from($schema) !!}
</script>
@endif
