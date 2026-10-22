@extends('layouts.app')

@php
    $entity = $page ?? $filter ?? null;

    // SEO-страница (article layout) vs SEO-фильтр (plain product grid)
    $isArticle = isset($page);
    $products   = $isArticle
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
/* FAQ chevron — CSS-only toggle */
details[open] .ah-faq-chev { transform: rotate(45deg); }
</style>
@endpush

@section('content')

{{-- ═══════════════════════════════════════════════════════════════
     ARTICLE LAYOUT — для SEO-страниц (isset $page)
════════════════════════════════════════════════════════════════ --}}
@if($isArticle)

@php
    $updatedAt      = $page->updated_at?->locale('ru')->isoFormat('MMMM YYYY');
    $plainText      = strip_tags(($page->intro ?? '') . ' ' . ($page->seo_text ?? ''));
    $wordCount      = str_word_count($plainText);
    $readingMinutes = max(1, (int) ceil($wordCount / 200));
    $categories     = $page->categories ?? collect();
    $faqItems       = is_array($page->faq) ? $page->faq : [];

    // Auto-extract h2 headings with id from seo_text for TOC
    $toc = [];
    if ($page->seo_text) {
        preg_match_all('/<h2[^>]*\sid=["\']([^"\']+)["\'][^>]*>(.*?)<\/h2>/si', $page->seo_text, $m);
        if (!empty($m[1])) {
            foreach ($m[1] as $i => $id) {
                $toc[] = ['id' => $id, 'text' => strip_tags($m[2][$i])];
            }
        }
        // Also check id before h2 tag
        if (empty($toc)) {
            preg_match_all('/<h2[^>]*id=["\']([^"\']+)["\'][^>]*>(.*?)<\/h2>/si', $page->seo_text, $m2);
            foreach ($m2[1] ?? [] as $i => $id) {
                $toc[] = ['id' => $id, 'text' => strip_tags($m2[2][$i])];
            }
        }
    }
    if ($products->isNotEmpty()) {
        $toc[] = ['id' => 'tovary', 'text' => 'Товары в наличии'];
    }
    if (!empty($faqItems)) {
        $toc[] = ['id' => 'faq', 'text' => 'Частые вопросы'];
    }
@endphp

<div class="ah-article-page">
  <div class="ah-container">

    {{-- ── Hero ───────────────────────────────────────────────── --}}
    <div class="ah-article-hero">
      <div class="ah-article-hero-content">
        <h1 class="ah-article-title">{{ $page->seoH1() }}</h1>
        @if($page->intro)
          <p class="ah-article-intro">{{ $page->intro }}</p>
        @endif
        <div class="ah-article-meta">
          @if($updatedAt)
            <span>Обновлено: {{ $updatedAt }}</span>
            <span aria-hidden="true">·</span>
          @endif
          <span>{{ $readingMinutes }}&nbsp;мин. чтения</span>
        </div>
      </div>

      @if(!empty($toc))
      <aside class="ah-article-toc" aria-labelledby="toc-title">
        <p id="toc-title" class="ah-article-toc-label">Содержание</p>
        <ul>
          @foreach($toc as $item)
          <li>
            <a href="#{{ $item['id'] }}">
              {{ $item['text'] }}<span aria-hidden="true">→</span>
            </a>
          </li>
          @endforeach
        </ul>
      </aside>
      @endif
    </div>

    {{-- ── Article body ────────────────────────────────────────── --}}
    @if($page->seo_text)
    <article class="ah-article-body" id="article-body">
      {!! $page->seo_text !!}
    </article>
    @endif

    {{-- ── Related products ───────────────────────────────────── --}}
    @if($products->isNotEmpty())
    <section id="tovary" class="ah-article-section" aria-labelledby="products-heading" style="scroll-margin-top:24px">
      <div class="ah-article-section-header">
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

    {{-- ── FAQ ──────────────────────────────────────────────────── --}}
    @if(!empty($faqItems))
    <section id="faq" class="ah-article-section ah-article-faq-section" aria-labelledby="faq-heading" style="scroll-margin-top:24px">
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

    {{-- ── CTA banner ───────────────────────────────────────────── --}}
    @php
        $ctaHref  = $categories->isNotEmpty()
            ? url('/catalog/' . $categories->first()->slug)
            : route('catalog');
    @endphp
    <section class="ah-article-cta" aria-labelledby="cta-heading">
      <div>
        <h2 id="cta-heading">Товары в наличии в Алматы</h2>
        <p>Доставка по Алматы и Казахстану. Бесплатная консультация по подбору.</p>
      </div>
      <a href="{{ $ctaHref }}" class="ah-button ah-button-primary">Перейти в каталог</a>
    </section>

  </div>{{-- .ah-container --}}
</div>{{-- .ah-article-page --}}

{{-- ═══════════════════════════════════════════════════════════════
     FILTER LAYOUT — для SEO-фильтров (isset $filter)
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
