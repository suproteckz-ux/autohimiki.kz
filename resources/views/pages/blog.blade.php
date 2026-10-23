@extends('layouts.app')

@section('title', $metaTitle)
@section('description', $metaDesc)

@section('canonical')
<link rel="canonical" href="{{ $canonical }}">
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
<div class="ah-container">

    <header class="ah-blog-header">
        <h1>Блог об автохимии и уходе за автомобилем</h1>
        <p>Полезные советы по выбору автохимии, уходу за автомобилем и сезонной подготовке. Обзоры средств, инструкции и рекомендации специалистов Autohimiki.kz.</p>
    </header>

    @if($posts->count())
    <div class="ah-blog-grid">
        @foreach($posts as $item)
        <article class="ah-blog-card">

            {{-- Cover image or placeholder --}}
            @if(!empty($item->image))
            <a href="{{ $item->url }}" class="ah-blog-card-img" tabindex="-1" aria-hidden="true">
                <img src="{{ $item->image }}"
                     alt="{{ $item->image_alt }}"
                     loading="lazy"
                     width="400"
                     height="225">
            </a>
            @else
            <a href="{{ $item->url }}" class="ah-blog-card-placeholder" tabindex="-1" aria-hidden="true">
                <svg width="40" height="40" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                </svg>
            </a>
            @endif

            <div class="ah-blog-card-body">
                @if(!empty($item->label))
                    <span class="ah-blog-card-label">{{ $item->label }}</span>
                @endif

                @if($item->date)
                    <time class="ah-blog-card-date" datetime="{{ $item->date->format('Y-m-d') }}">
                        {{ $item->date->translatedFormat('d F Y') }}
                    </time>
                @endif

                <h2 class="ah-blog-card-title">
                    <a href="{{ $item->url }}">{{ $item->title }}</a>
                </h2>

                @if(!empty($item->intro))
                    <p class="ah-blog-card-intro">{{ $item->intro }}</p>
                @endif

                <a href="{{ $item->url }}" class="ah-blog-card-link">
                    Читать статью
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>

        </article>
        @endforeach
    </div>

    @if($posts->hasPages())
    <div class="ah-blog-pagination">
        {{ $posts->links() }}
    </div>
    @endif

    @else
    <div class="ah-blog-empty">
        <div class="ah-blog-empty-icon" aria-hidden="true">📝</div>
        <p class="ah-blog-empty-title">Статей пока нет</p>
        <p class="ah-blog-empty-sub">Скоро здесь появятся полезные материалы об автохимии</p>
    </div>
    @endif

</div>
@endsection

@section('schema')
<x-schema.breadcrumbs :items="[
    ['name' => 'Блог', 'url' => url('/blog')],
]"/>
@endsection
