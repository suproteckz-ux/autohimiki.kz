@props(['category', 'variant' => 'default'])
<a @class(['ah-category-card', 'ah-category-card--handoff' => $variant === 'handoff']) href="{{ $category->url ?? url('/catalog/' . $category->slug) }}">
    @if($variant === 'handoff')
        <span class="ah-category-icon-wash"><x-category.icon :slug="$category->slug" /></span>
        <div class="ah-category-copy">
            <h3>{{ $category->name }}</h3>
            <span>@if(isset($category->products_count) && $category->products_count > 0){{ $category->products_count }} товаров@endif</span>
        </div>
        <b aria-hidden="true">→</b>
    @else
        <div class="ah-category-image">
            @if($category->image)<img src="{{ asset('storage/' . ($category->image_webp ?? $category->image)) }}" alt="{{ $category->name }}" loading="lazy" width="240" height="120">
            @else<span class="ah-placeholder" aria-hidden="true">АВТОХИМИЯ<br>И ДЕТЕЙЛИНГ</span>@endif
        </div>
        <div class="ah-category-copy"><h3>{{ $category->name }}</h3><span>@if(isset($category->products_count) && $category->products_count > 0){{ $category->products_count }} товаров@endif</span><b aria-hidden="true">→</b></div>
    @endif
</a>
