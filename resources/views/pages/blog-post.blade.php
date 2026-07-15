@extends('layouts.app')

@section('title', $post->meta_title ?? $post->title)
@section('description', $post->meta_description ?? '')

@section('content')
<article class="container mx-auto px-4 py-8 max-w-4xl">
    <h1 class="text-3xl font-bold mb-4">{{ $post->h1 ?? $post->title }}</h1>

    @if($post->published_at)
        <time class="block text-sm text-gray-500 mb-6" datetime="{{ $post->published_at->toDateString() }}">
            {{ $post->published_at->format('d.m.Y') }}
        </time>
    @endif

    @if($post->cover_image)
        <img class="w-full rounded mb-8" src="{{ asset('storage/' . ($post->cover_image_webp ?? $post->cover_image)) }}" alt="{{ $post->title }}">
    @endif

    <div class="prose max-w-none">
        {!! $post->content ?? $post->body ?? '' !!}
    </div>

    @if($post->products->isNotEmpty())
        <h2 class="text-2xl font-semibold mt-10 mb-4">Related products</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($post->products as $product)
                <x-product.card :product="$product" />
            @endforeach
        </div>
    @endif
</article>
@endsection
