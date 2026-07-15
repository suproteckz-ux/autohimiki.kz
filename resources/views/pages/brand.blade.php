@extends('layouts.app')

@section('title', ($brand->meta_title ?? $brand->name) . ' | Autohimiki.kz')
@section('description', $brand->meta_description ?? '')

@section('canonical')
    <link rel="canonical" href="{{ $canonical }}">
@endsection

@if($noindex)
    @section('noindex', true)
@endif

@section('content')
<section class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold mb-6">{{ $brand->h1 ?? $brand->name }}</h1>

    @if($brand->description)
        <div class="prose max-w-none mb-8">{!! $brand->description !!}</div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @forelse($products as $product)
            <x-product.card :product="$product" />
        @empty
            <p class="text-gray-600">Products are not available yet.</p>
        @endforelse
    </div>

    <div class="mt-8">
        {{ $products->links() }}
    </div>
</section>
@endsection
