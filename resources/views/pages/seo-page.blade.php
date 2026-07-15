@extends('layouts.app')

@php
    $entity = $page ?? $filter;
    $items = $products ?? ($page->products ?? collect());
@endphp

@section('title', $entity->meta_title ?? $entity->name)
@section('description', $entity->meta_description ?? '')

@isset($canonical)
    @section('canonical')
        <link rel="canonical" href="{{ $canonical }}">
    @endsection
@endisset

@section('content')
<section class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold mb-6">{{ method_exists($entity, 'seoH1') ? $entity->seoH1() : ($entity->h1 ?? $entity->name) }}</h1>

    @if($items->isNotEmpty())
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mb-8">
            @foreach($items as $product)
                <x-product.card :product="$product" />
            @endforeach
        </div>
    @endif

    @if($entity->seo_text)
        <div class="prose max-w-none">{!! $entity->seo_text !!}</div>
    @endif
</section>
@endsection
