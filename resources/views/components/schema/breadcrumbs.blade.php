@props(['items' => []])

@php
    // Всегда добавляем "Главная" первым элементом
    $crumbs = array_merge(
        [['name' => 'Главная', 'url' => url('/')]],
        $items
    );

    // Убираем null и пустые элементы, переиндексируем
    $crumbs = array_values(array_filter($crumbs, fn($c) => ! empty($c['name'])));
@endphp

@if(count($crumbs) > 1)
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    "itemListElement": [
        @foreach($crumbs as $index => $crumb)
        {
            "@type": "ListItem",
            "position": {{ $index + 1 }},
            "name": "{{ addslashes($crumb['name']) }}"
            @if(! empty($crumb['url']))
            , "item": "{{ $crumb['url'] }}"
            @endif
        }{{ ! $loop->last ? ',' : '' }}
        @endforeach
    ]
}
</script>
@endif
