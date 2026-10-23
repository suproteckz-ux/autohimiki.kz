@props(['items' => []])

@php
    $allItems = $items;
    $total    = count($allItems);
    $parent   = $total >= 2 ? $allItems[$total - 2] : null;
@endphp

{{-- Desktop: full breadcrumb trail --}}
@if($total > 0)
<nav class="ah-breadcrumbs" aria-label="Breadcrumbs">
    @foreach($allItems as $i => $item)
        @if($i > 0)
            <span class="ah-breadcrumbs-sep" aria-hidden="true">/</span>
        @endif
        @if(!empty($item['url']) && $i < $total - 1)
            <a href="{{ $item['url'] }}">{{ $item['name'] }}</a>
        @else
            <span class="ah-breadcrumbs-current" @if($loop->last) aria-current="page" @endif>{{ $item['name'] }}</span>
        @endif
    @endforeach
</nav>
@endif

{{-- Mobile: single "← parent" back link --}}
@if($parent && !empty($parent['url']))
<a class="ah-breadcrumb-back" href="{{ $parent['url'] }}" aria-label="Назад: {{ $parent['name'] }}">
    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
         stroke-linecap="round" stroke-linejoin="round">
        <path d="M19 12H5M12 19l-7-7 7-7"/>
    </svg>
    {{ $parent['name'] }}
</a>
@endif
