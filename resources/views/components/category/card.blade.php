@props(['category'])
<a class="ah-category-card" href="{{ $category->url ?? url('/catalog/' . $category->slug) }}">
    <div class="ah-category-image">
        @if($category->image)<img src="{{ asset('storage/' . ($category->image_webp ?? $category->image)) }}" alt="{{ $category->name }}" loading="lazy" width="240" height="120">
        @else<span class="ah-placeholder" aria-hidden="true">АВТОХИМИЯ<br>И ДЕТЕЙЛИНГ</span>@endif
    </div>
    <div class="ah-category-copy"><h3>{{ $category->name }}</h3><span>@if(isset($category->products_count) && $category->products_count > 0){{ $category->products_count }} товаров@endif</span><b aria-hidden="true">→</b></div>
</a>
