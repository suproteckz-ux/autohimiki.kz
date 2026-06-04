@extends('layouts.app')

@section('title', 'Бренды автохимии в Алматы | Autohimiki.kz')
@section('description', 'Все бренды автохимии в нашем каталоге: Meguiar\'s, Koch Chemie, Shine Systems, Turtle Wax, Ma-Fra, Sonax и другие. Купить в Алматы.')

{{-- Canonical: страница всегда /brand без параметров --}}
@section('canonical')
<link rel="canonical" href="{{ route('brands') }}">
@endsection

@php
    $ogImage = asset('img/og-default.jpg');
    $ogType  = 'website';
@endphp

@section('breadcrumbs')
<x-ui.breadcrumbs :items="[['name' => 'Бренды']]"/>
@endsection

@section('content')
<div class="container mx-auto px-4 py-8">

    <h1 class="text-2xl md:text-3xl font-bold text-gray-900 mb-2">
        Бренды автохимии
    </h1>
    <p class="text-gray-500 text-sm mb-8">
        {{ $brands->count() }} {{ trans_choice('бренд|бренда|брендов', $brands->count()) }}
        в нашем каталоге
    </p>

    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
        @foreach($brands as $brand)
        <a href="{{ $brand->url }}"
           class="flex flex-col items-center gap-3 p-5 bg-white border border-gray-100
                  rounded-2xl hover:border-primary-300 hover:shadow-md
                  transition-all duration-200 text-center group">
            @if($brand->logo)
            <div class="h-14 flex items-center justify-center w-full">
                <img src="{{ asset('storage/' . ($brand->logo_webp ?? $brand->logo)) }}"
                     alt="Автохимия {{ $brand->name }}"
                     loading="lazy"
                     width="80" height="56"
                     class="max-h-14 max-w-full object-contain
                            grayscale group-hover:grayscale-0
                            transition-all duration-200">
            </div>
            @else
            <div class="h-14 flex items-center">
                <span class="font-bold text-gray-700 text-lg group-hover:text-primary-600
                             transition-colors">
                    {{ $brand->name }}
                </span>
            </div>
            @endif

            <span class="text-sm font-medium text-gray-600 group-hover:text-primary-600
                         transition-colors">
                {{ $brand->name }}
            </span>

            <span class="text-xs text-gray-400">
                {{ $brand->products_count }}
                {{ trans_choice('товар|товара|товаров', $brand->products_count) }}
            </span>
        </a>
        @endforeach
    </div>

</div>
@endsection

@section('schema')
<x-schema.breadcrumbs :items="[
    ['name' => 'Бренды', 'url' => route('brands')],
]"/>
@endsection
