@extends('layouts.app')

@section('title', $product->meta_title ?? $product->name)
@section('description', $product->meta_description ?? '')

@section('canonical')
    <link rel="canonical" href="{{ $canonical }}">
@endsection

@section('content')
<section class="container mx-auto px-4 py-8">
    <div class="grid gap-8 lg:grid-cols-2">
        <div>
            @if($product->main_image)
                <img class="w-full rounded border" src="{{ asset('storage/' . ($product->main_image_webp ?? $product->main_image)) }}" alt="{{ $product->main_image_alt ?? $product->name }}">
            @else
                <div class="aspect-square rounded border bg-gray-50"></div>
            @endif
        </div>

        <div>
            <h1 class="text-3xl font-bold mb-4">{{ $product->h1 ?? $product->name }}</h1>

            @if($product->brand)
                <p class="text-gray-600 mb-2">Brand: <a class="text-blue-700" href="{{ route('brand.show', $product->brand->slug) }}">{{ $product->brand->name }}</a></p>
            @endif

            @if($product->category)
                <p class="text-gray-600 mb-6">Category: <a class="text-blue-700" href="{{ route('catalog.category', $product->category->slug) }}">{{ $product->category->name }}</a></p>
            @endif

            <div class="text-2xl font-semibold mb-6">
                {{ number_format((float) $product->price, 0, '.', ' ') }} KZT
            </div>

            @if($product->description)
                <div class="prose max-w-none">{!! $product->description !!}</div>
            @endif
        </div>
    </div>
</section>
@endsection
