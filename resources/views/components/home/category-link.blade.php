@props(['categories', 'name'])
@php
    // Only active root categories already supplied by HomeController. No guessed slugs.
    $category = $categories->first(fn ($item) => mb_strtolower(trim($item->name)) === mb_strtolower($name));
@endphp
@if($category)<a href="{{ $category->url }}">{{ $slot }}</a>@else{{ $slot }}@endif
