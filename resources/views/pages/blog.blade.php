@extends('layouts.app')

@section('title', $metaTitle)
@section('description', $metaDesc)

@section('canonical')
<link rel="canonical" href="{{ $canonical }}">

{{-- rel prev/next для серии страниц пагинации --}}
@if(($currentPage ?? 1) > 1 && $posts->previousPageUrl())
    <link rel="prev" href="{{ $posts->previousPageUrl() }}">
@endif
@if($posts->hasMorePages())
    <link rel="next" href="{{ $posts->nextPageUrl() }}">
@endif
@endsection

@section('breadcrumbs')
<x-ui.breadcrumbs :items="[['name' => 'Блог']]"/>
@endsection

@section('content')
<div class="container mx-auto px-4 py-8">

    <h1 class="text-2xl md:text-3xl font-bold text-gray-900 mb-2">
        Блог об автохимии
    </h1>
    <p class="text-gray-500 text-sm mb-8">
        Советы по уходу за автомобилем, обзоры брендов и инструкции по применению
    </p>

    @if($posts->count())
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach($posts as $post)
        <article class="bg-white border border-gray-100 rounded-2xl overflow-hidden
                        hover:shadow-md transition-shadow duration-200 flex flex-col">

            {{-- Обложка --}}
            @if($post->cover_image)
            <a href="{{ $post->url }}" class="block aspect-video overflow-hidden bg-gray-100 flex-shrink-0">
                <img src="{{ asset('storage/' . ($post->cover_image_webp ?? $post->cover_image)) }}"
                     alt="{{ $post->cover_image_alt ?? $post->title }}"
                     loading="lazy"
                     width="400"
                     height="225"
                     class="w-full h-full object-cover hover:scale-105 transition-transform duration-300">
            </a>
            @endif

            <div class="p-5 flex flex-col flex-grow">
                <time class="text-xs text-gray-400 mb-2 block"
                      datetime="{{ $post->published_at?->format('Y-m-d') }}">
                    {{ $post->published_at?->translatedFormat('d F Y') }}
                </time>

                <h2 class="font-bold text-gray-900 mb-3 leading-snug flex-grow">
                    <a href="{{ $post->url }}"
                       class="hover:text-primary-600 transition-colors">
                        {{ $post->title }}
                    </a>
                </h2>

                <a href="{{ $post->url }}"
                   class="inline-flex items-center gap-1 text-sm text-primary-600
                          font-medium hover:underline mt-auto">
                    Читать статью
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>
        </article>
        @endforeach
    </div>

    {{-- Пагинация --}}
    @if($posts->hasPages())
    <div class="mt-10">
        {{ $posts->links() }}
    </div>
    @endif

    @else
    <div class="text-center py-16 text-gray-400">
        <div class="text-5xl mb-4">📝</div>
        <p class="text-lg font-medium text-gray-600">Статей пока нет</p>
        <p class="text-sm mt-2">Скоро здесь появятся полезные материалы</p>
    </div>
    @endif

</div>
@endsection

@section('schema')
<x-schema.breadcrumbs :items="[
    ['name' => 'Блог', 'url' => url('/blog')],
]"/>
@endsection
