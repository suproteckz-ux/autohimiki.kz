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
    $updatedAt    = $page->updated_at?->locale('ru')->isoFormat('MMMM YYYY');
    $categories   = $page->categories ?? collect();
    $faqItems     = is_array($page->faq) ? $page->faq : [];
    $hasHeroImage = !empty($page->hero_image);

    // 1. Decode HTML entities: Filament/DB may store &lt;h2&gt; instead of <h2>
    // 2. Sanitize with Filament's Str::sanitizeHtml() (Symfony HtmlSanitizer):
    //    strips <script>, onclick/on*, javascript: hrefs — preserves id attributes
    //    needed for TOC anchors. Filament's own comment: "Never use {!! !!} unsanitized."
    $seoText = $page->seo_text
        ? \Illuminate\Support\Str::sanitizeHtml(
            html_entity_decode($page->seo_text, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        )
        : null;

    $plainText      = strip_tags(($page->intro ?? '') . ' ' . ($seoText ?? ''));
    $wordCount      = str_word_count($plainText);
    $readingMinutes = max(1, (int) ceil($wordCount / 200));

    // Extract all h2 headings, injecting id where absent, build TOC
    $toc   = [];
    $h2Idx = 0;
    if ($seoText) {
        $seoText = preg_replace_callback(
            '/<h2([^>]*)>(.*?)<\/h2>/si',
            function ($m) use (&$toc, &$h2Idx) {
                $attrs = $m[1];
                $inner = $m[2];
                $text  = trim(strip_tags($inner));
                if (!$text) return $m[0];
                if (preg_match('/\bid=["\']([^"\']+)["\']/', $attrs, $im)) {
                    $id = $im[1];
                } else {
                    $h2Idx++;
                    $id    = 'section-' . $h2Idx;
                    $attrs .= ' id="' . $id . '"';
                }
                $toc[] = ['id' => $id, 'text' => $text];
                return '<h2' . $attrs . '>' . $inner . '</h2>';
            },
            $seoText
        );
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
    $ctaText    = $page->cta_button_text ?: 'Перейти в каталог';
    $ctaHeading = $page->cta_heading     ?: 'Товары в наличии в Алматы';
    $ctaDesc    = $page->cta_description ?: 'Доставка по Алматы и Казахстану. Бесплатная консультация по подбору.';
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

        @if($seoText)
        <article class="ah-seoart-body" id="article-body">
          {!! $seoText !!}
        </article>
        @endif

        {{-- Related products --}}
        @if($products->isNotEmpty())
        <section id="tovary" class="ah-seoart-section" aria-labelledby="products-heading">
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

        {{-- Comparison table --}}
        @if($page->show_comparison && $products->count() >= 2)
        <section class="ah-seoart-section ah-seoart-compare">
          <div class="ah-seoart-section-header">
            <h2>Сравнение товаров</h2>
          </div>
          <div class="ah-seoart-compare-wrap">
            <table class="ah-seoart-compare-tbl">
              <thead>
                <tr>
                  <th>Товар</th>
                  <th>Характеристики</th>
                  <th>Цена</th>
                  <th>Наличие</th>
                </tr>
              </thead>
              <tbody>
                @foreach($products->take(6) as $cmpProduct)
                @php
                  $cmpAttrs = [];
                  $cmpRaw   = is_array($cmpProduct->attributes) ? $cmpProduct->attributes : [];
                  foreach (array_slice($cmpRaw, 0, 2, true) as $cmpKey => $cmpVal) {
                    if (is_array($cmpVal)) {
                      $ck = $cmpVal['name'] ?? $cmpVal['k'] ?? '';
                      $cv = $cmpVal['value'] ?? $cmpVal['v'] ?? '';
                    } else {
                      $ck = is_string($cmpKey) ? $cmpKey : '';
                      $cv = is_string($cmpVal) ? $cmpVal : '';
                    }
                    if ($ck !== '' && $cv !== '') $cmpAttrs[] = ['k' => $ck, 'v' => $cv];
                  }
                @endphp
                <tr>
                  <td>
                    <div class="ah-cmp-product">
                      <div class="ah-cmp-img">
                        @if(!empty($cmpProduct->main_image))
                          <img src="{{ asset('storage/' . $cmpProduct->main_image) }}"
                               alt="{{ $cmpProduct->name }}" loading="lazy">
                        @endif
                      </div>
                      <div>
                        @if($cmpProduct->brand)
                          <p class="ah-cmp-brand">{{ $cmpProduct->brand->name }}</p>
                        @endif
                        <a href="{{ route('product.show', $cmpProduct->slug) }}" class="ah-cmp-name">{{ $cmpProduct->name }}</a>
                      </div>
                    </div>
                  </td>
                  <td>
                    @if($cmpAttrs)
                      @foreach($cmpAttrs as $cmpAttr)
                        <p class="ah-cmp-spec"><span>{{ $cmpAttr['k'] }}:</span> {{ $cmpAttr['v'] }}</p>
                      @endforeach
                    @else
                      <span class="ah-cmp-na">Не указано</span>
                    @endif
                  </td>
                  <td><span class="ah-cmp-price">{{ number_format($cmpProduct->price, 0, '.', ' ') }}&nbsp;₸</span></td>
                  <td>
                    <span @class(['ah-cmp-stock', 'is-in' => $cmpProduct->in_stock])>
                      {{ $cmpProduct->in_stock ? '● В наличии' : '● Нет' }}
                    </span>
                  </td>
                </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </section>
        @endif

        {{-- FAQ --}}
        @if(!empty($faqItems))
        <section id="faq" class="ah-seoart-section ah-seoart-faq-section" aria-labelledby="faq-heading">
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

      {{-- Sticky sidebar with TOC (collapsible on mobile) --}}
      @if(!empty($toc))
      <aside class="ah-seoart-sidebar" aria-labelledby="toc-title">
        <details class="ah-seoart-toc" open>
          <summary class="ah-seoart-toc-summary" id="toc-title">
            Содержание
            <span class="ah-seoart-toc-chev" aria-hidden="true">▾</span>
          </summary>
          <ul>
            @foreach($toc as $item)
            <li>
              <a href="#{{ $item['id'] }}">{{ $item['text'] }}<span aria-hidden="true">→</span></a>
            </li>
            @endforeach
          </ul>
        </details>
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
