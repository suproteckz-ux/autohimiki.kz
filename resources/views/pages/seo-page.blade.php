@extends('layouts.app')

@php
    $entity    = $page ?? $filter ?? null;
    $isArticle = isset($page);
    $products  = $isArticle
        ? ($page->products ?? collect())
        : ($products ?? collect());
@endphp

@section('title', $entity ? $entity->seoTitle() : '')
@section('description', $entity ? $entity->seoDescription() : '')

@isset($canonical)
    @section('canonical')
        <link rel="canonical" href="{{ $canonical }}">
    @endsection
@endisset

@if($isArticle)
    @section('canonical')
        <link rel="canonical" href="{{ url('/' . $page->slug) }}">
    @endsection
@endif

@section('breadcrumbs')
<x-ui.breadcrumbs :items="$breadcrumbs"/>
@endsection

@push('scripts')
<style>
details[open] .ah-faq-chev { transform: rotate(45deg); }
</style>
@endpush

@section('content')

{{-- ═══════════════════════════════════════════════════════════════
     ARTICLE LAYOUT — SEO-страницы (isset $page)
════════════════════════════════════════════════════════════════ --}}
@if($isArticle)

@php
    $updatedAt      = $page->updated_at?->locale('ru')->isoFormat('MMMM YYYY');
    $plainText      = strip_tags(($page->intro ?? '') . ' ' . ($page->seo_text ?? ''));
    $wordCount      = str_word_count($plainText);
    $readingMinutes = max(1, (int) ceil($wordCount / 200));
    $categories     = $page->categories ?? collect();
    $faqItems       = is_array($page->faq) ? $page->faq : [];
    $hasHeroImage   = !empty($page->hero_image);

    // Auto-extract h2 headings with id from seo_text for TOC
    $toc = [];
    if ($page->seo_text) {
        preg_match_all('/<h2[^>]*id=["\']([^"\']+)["\'][^>]*>(.*?)<\/h2>/si', $page->seo_text, $m);
        foreach ($m[1] ?? [] as $i => $id) {
            $toc[] = ['id' => $id, 'text' => strip_tags($m[2][$i])];
        }
    }
    if ($products->isNotEmpty()) {
        $toc[] = ['id' => 'tovary', 'text' => 'Товары в наличии'];
    }
    if (!empty($faqItems)) {
        $toc[] = ['id' => 'faq', 'text' => 'Частые вопросы'];
    }

    // CTA fields with fallbacks
    $ctaLink    = $page->cta_link
        ?: ($categories->isNotEmpty() ? url('/catalog/' . $categories->first()->slug) : route('catalog'));
    $ctaText    = $page->cta_button_text    ?: 'Перейти в каталог';
    $ctaHeading = $page->cta_heading        ?: 'Товары в наличии в Алматы';
    $ctaDesc    = $page->cta_description    ?: 'Доставка по Алматы и Казахстану. Бесплатная консультация по подбору.';
@endphp

<div class="ah-seoart-page">

  {{-- ── Dark Hero ──────────────────────────────────────────────── --}}
  <div class="ah-seoart-hero{{ $hasHeroImage ? ' ah-seoart-hero--has-image' : '' }}">
    <div class="ah-container">
      <div class="ah-seoart-hero-inner">

        <div class="ah-seoart-hero-body">
          @if($page->hero_label)
            <span class="ah-seoart-hero-label">{{ $page->hero_label }}</span>
          @endif

          <h1 class="ah-seoart-hero-title">{{ $page->seoH1() }}</h1>

          @if($page->intro)
            <p class="ah-seoart-hero-intro">{{ $page->intro }}</p>
          @endif

          <div class="ah-seoart-hero-meta">
            @if($updatedAt)
              <span>Обновлено: {{ $updatedAt }}</span>
              <span class="ah-seoart-hero-meta-sep" aria-hidden="true">·</span>
            @endif
            <span>{{ $readingMinutes }}&nbsp;мин. чтения</span>
          </div>
        </div>

        @if($hasHeroImage)
        <div class="ah-seoart-hero-image">
          <img src="{{ asset('storage/' . $page->hero_image) }}"
               alt="{{ $page->hero_image_alt ?: $page->seoH1() }}"
               loading="eager"
               width="520" height="346">
        </div>
        @endif

      </div>
    </div>
  </div>

  {{-- ── Two-column: article + sticky sidebar ─────────────────── --}}
  <div class="ah-container">
    <div class="ah-seoart-grid">

      {{-- Main article column --}}
      <div class="ah-seoart-content">

        @if($page->seo_text)
        <article class="ah-seoart-body" id="article-body">
          {!! $page->seo_text !!}
        </article>
        @endif

        {{-- Related products --}}
        @if($products->isNotEmpty())
        <section id="tovary" class="ah-seoart-section" aria-labelledby="products-heading" style="scroll-margin-top:24px">
          <div class="ah-seoart-section-header">
            <h2 id="products-heading">Рекомендуемые товары</h2>
            @if($categories->isNotEmpty())
              <a href="{{ url('/catalog/' . $categories->first()->slug) }}">Все в каталоге →</a>
            @endif
          </div>
          <div class="ah-seo-product-grid">
            @foreach($products as $product)
              <x-product.seo-card :product="$product" />
            @endforeach
          </div>
        </section>
        @endif

        {{-- Comparison block --}}
        @if($page->show_comparison && $products->count() >= 2)
        <section class="ah-seoart-section ah-seoart-compare">
          <div class="ah-seoart-section-header">
            <h2>Сравнение товаров</h2>
          </div>
          <div class="ah-seoart-compare-grid">
            @foreach($products->take(4) as $product)
            <div class="ah-seoart-compare-card">
              <div class="ah-seoart-compare-img">
                @if($product->images->isNotEmpty())
                  <img src="{{ asset('storage/' . $product->images->first()->path) }}"
                       alt="{{ $product->name }}" loading="lazy">
                @else
                  <span class="ah-placeholder">Нет фото</span>
                @endif
              </div>
              <div class="ah-seoart-compare-body">
                <p class="ah-seoart-compare-name">
                  <a href="{{ url($product->url ?? '/products/' . $product->slug) }}">{{ $product->name }}</a>
                </p>
                <p class="ah-seoart-compare-price">{{ number_format($product->price, 0, '.', ' ') }}&nbsp;₸</p>
              </div>
            </div>
            @endforeach
          </div>
        </section>
        @endif

        {{-- FAQ --}}
        @if(!empty($faqItems))
        <section id="faq" class="ah-seoart-section ah-seoart-faq-section" aria-labelledby="faq-heading" style="scroll-margin-top:24px">
          <h2 id="faq-heading">Частые вопросы</h2>
          <div class="ah-article-faq">
            @foreach($faqItems as $i => $item)
            @php
                $q = $item['question'] ?? $item['q'] ?? '';
                $a = $item['answer']   ?? $item['a'] ?? '';
            @endphp
            @if($q)
            <details @if($i === 0) open @endif>
              <summary>
                <h3>{{ $q }}</h3>
                <span class="ah-faq-chev" aria-hidden="true">+</span>
              </summary>
              <div class="ah-article-faq-answer">{{ $a }}</div>
            </details>
            @endif
            @endforeach
          </div>
        </section>
        @endif

      </div>{{-- .ah-seoart-content --}}

      {{-- Sticky sidebar with TOC --}}
      @if(!empty($toc))
      <aside class="ah-seoart-sidebar" aria-labelledby="toc-title">
        <div class="ah-seoart-toc">
          <p id="toc-title" class="ah-seoart-toc-label">Содержание</p>
          <ul>
            @foreach($toc as $item)
            <li>
              <a href="#{{ $item['id'] }}">{{ $item['text'] }}<span aria-hidden="true">→</span></a>
            </li>
            @endforeach
          </ul>
        </div>
      </aside>
      @endif

    </div>{{-- .ah-seoart-grid --}}

    {{-- Dark CTA footer --}}
    <section class="ah-seoart-cta" aria-labelledby="cta-section-heading">
      <div class="ah-seoart-cta-body">
        <h2 id="cta-section-heading">{{ $ctaHeading }}</h2>
        <p>{{ $ctaDesc }}</p>
      </div>
      <a href="{{ $ctaLink }}" class="ah-button ah-button-orange">{{ $ctaText }}</a>
    </section>

  </div>{{-- .ah-container --}}
</div>{{-- .ah-seoart-page --}}

{{-- ═══════════════════════════════════════════════════════════════
     FILTER LAYOUT — SEO-фильтры (isset $filter)
════════════════════════════════════════════════════════════════ --}}
@else

<section class="ah-container ah-section" style="padding-block:32px 48px">
    <h1 class="ah-article-title" style="margin-bottom:24px">
        {{ method_exists($entity, 'seoH1') ? $entity->seoH1() : ($entity->h1 ?? $entity->name ?? '') }}
    </h1>

    @if(!$products->isEmpty())
        <div class="ah-product-grid ah-catalog-product-grid mb-8">
            @foreach($products as $product)
                <x-product.card :product="$product" />
            @endforeach
        </div>
        {{ $products->links() }}
    @endif

    @if($entity && $entity->seo_text)
        <div class="ah-category-seo-copy prose">{!! $entity->seo_text !!}</div>
    @endif
</section>

@endif

@endsection
