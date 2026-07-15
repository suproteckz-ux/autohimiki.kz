@extends('layouts.app')

@section('title', 'Catalog | Autohimiki.kz')

@section('content')
<section class="container mx-auto px-4 py-10">
    <h1 class="mb-6 text-2xl font-bold">Catalog</h1>

    <div class="grid gap-4 md:grid-cols-3">
        @foreach($categories as $category)
            <a href="{{ $category->url }}" class="rounded border border-gray-200 bg-white p-4 font-semibold">
                {{ $category->name }}
            </a>
        @endforeach
    </div>
</section>
@endsection
