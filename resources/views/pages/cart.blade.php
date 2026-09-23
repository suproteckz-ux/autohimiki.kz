@extends('layouts.app')
@section('title', 'Корзина — Autohimiki.kz')
@section('noindex', '1')
@section('content')
<div class="ah-container ah-commerce">
    <h1>Корзина</h1>
    @include('components.ui.cart-feedback')
    @if(!$items)
        <p>Ваша корзина пока пуста.</p>
        <a class="ah-button ah-button-orange" href="{{ route('catalog') }}">Перейти в каталог</a>
    @else
    <div class="ah-cart-items">
    @foreach($items as $item)
        <article class="ah-cart-item">
            @php
                $imagePath = $item['product']?->main_image;
                $hasImage = is_string($imagePath) && trim($imagePath) !== ''
                    && !str_contains($imagePath, '..') && !preg_match('#^(?:/|[a-z]+:)|\\\\#i', $imagePath)
                    && \Illuminate\Support\Facades\Storage::disk('public')->exists($imagePath);
            @endphp
            <div class="ah-cart-photo">
                @if($hasImage)
                <img src="{{ asset('storage/'.$imagePath) }}" alt="{{ $item['product']->name }}" width="96" height="96" loading="lazy">
                @else
                <span class="ah-cart-placeholder" role="img" aria-label="Фото товара пока нет"><svg aria-hidden="true" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M3 7l9-4 9 4v10l-9 4-9-4V7zm0 0l9 4 9-4M12 11v10"/></svg></span>
                @endif
            </div>
            <div class="ah-cart-description">
                <h2>@if($item['product']?->is_active)<a href="{{ $item['product']->url }}">{{ $item['product']->name }}</a>@else{{ $item['product']?->name ?? 'Товар удалён' }}@endif</h2>
                <p>SKU: {{ $item['product']?->sku ?? '—' }}</p>
                <p>{{ number_format((float) ($item['product']?->price ?? 0), 2, '.', ' ') }} ₸ / шт.</p>
                @if(!$item['valid'])<p class="ah-cart-error" role="alert">Товар недоступен или остаток изменился. Доступно: {{ $item['product']?->is_active ? $item['product']->quantity : 0 }}. Измените количество или удалите товар.</p>@endif
            </div>
            <div class="ah-cart-controls">
                @if($item['product']?->is_active && $item['product']->quantity > 0)
                <form method="POST" action="{{ route('cart.update', $item['id']) }}" class="ah-cart-quantity">
                    @csrf @method('PATCH')
                    <button type="submit" name="quantity" value="{{ max(1, min($item['quantity'] - 1, $item['product']->quantity)) }}" aria-label="Уменьшить количество {{ $item['product']->name }}" @disabled($item['quantity'] <= 1)>−</button>
                    <span aria-label="Количество">{{ $item['quantity'] }}</span>
                    <button type="submit" name="quantity" value="{{ $item['quantity'] + 1 }}" aria-label="Увеличить количество {{ $item['product']->name }}" @disabled($item['quantity'] >= $item['product']->quantity)>+</button>
                </form>
                @else<p>Количество: {{ $item['quantity'] }}</p>@endif
                <strong>{{ number_format($item['subtotal'] / 100, 2, '.', ' ') }} ₸</strong>
                <form method="POST" action="{{ route('cart.destroy', $item['id']) }}">@csrf @method('DELETE')<button class="ah-cart-remove" type="submit">Удалить</button></form>
            </div>
        </article>
    @endforeach
    </div>
    <div class="ah-cart-total"><strong>Итого: {{ number_format($total / 100, 2, '.', ' ') }} ₸</strong><p>Стоимость доставки уточняется отдельно. Самовывоз — бесплатно.</p></div>
    <div class="ah-commerce-actions">
        @if($available)<a class="ah-button ah-button-orange" href="{{ route('checkout.index') }}">Оформить заказ</a>@else<p class="ah-cart-error">Для оформления исправьте недоступные позиции.</p>@endif
        <a class="ah-button" href="{{ route('catalog') }}">Продолжить покупки</a>
    </div>
    @endif
</div>
@endsection
