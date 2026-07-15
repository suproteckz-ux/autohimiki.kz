@extends('layouts.app')

@section('title', 'Search | Autohimiki.kz')

@section('content')
<section class="container mx-auto px-4 py-10">
    <h1 class="text-2xl font-bold mb-6">Search</h1>

    <form action="{{ route('search') }}" method="get" class="mb-8">
        <input
            type="search"
            name="q"
            value="{{ $query ?? '' }}"
            class="w-full rounded border border-gray-300 px-4 py-3"
        >
    </form>

    @if(($products ?? collect())->count())
        <div class="grid gap-4 md:grid-cols-3">
            @foreach($products as $product)
                <x-product.card :product="$product"/>
            @endforeach
        </div>
    @elseif(!empty($query))
        <p>No products found.</p>
    @endif
</section>
@endsection
