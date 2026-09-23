@extends('layouts.app')
@section('title', 'Оформление заказа — Autohimiki.kz')
@section('noindex', '1')
@section('content')
<div class="ah-container ah-commerce">
    <h1>Оформление заказа</h1>
    <a href="{{ route('cart.index') }}">← Вернуться в корзину</a>
    @include('components.ui.cart-feedback')
    <div class="ah-checkout-grid">
    <form method="POST" action="{{ route('checkout.store') }}" class="ah-checkout-form" x-data="{ delivery: {{ \Illuminate\Support\Js::from(old('delivery_method', 'pickup')) }}, submitting: false }" @submit="submitting = true">
        @csrf
        <input type="hidden" name="checkout_token" value="{{ session('checkout_token') }}">
        <label>Имя <input name="name" value="{{ old('name') }}" autocomplete="given-name" required minlength="2" maxlength="100"></label>
        <label>Телефон <input name="phone" type="tel" inputmode="tel" value="{{ old('phone') }}" autocomplete="tel" required maxlength="32" placeholder="+7 (700) 123-45-67"></label>
        <label>Город <input name="city" value="{{ old('city') }}" autocomplete="address-level2" required minlength="2" maxlength="100"></label>
        <label>Способ получения
            <select name="delivery_method" x-model="delivery" required>
                @foreach(\App\Models\Order::DELIVERY as $value => $label)<option value="{{ $value }}" @selected(old('delivery_method', 'pickup') === $value)>{{ $label }}</option>@endforeach
            </select>
        </label>
        <div class="ah-delivery-notes">
            <p x-show="delivery === 'pickup'">Самовывоз — бесплатно.</p>
            <p x-show="delivery === 'yandex'">Стоимость доставки рассчитывается по тарифам Яндекс Доставки.</p>
            <p x-show="delivery === 'kazpost'">Стоимость доставки рассчитывается после оформления заказа.</p>
        </div>
        <label x-show="delivery !== 'pickup'">Адрес доставки <input name="address" value="{{ old('address') }}" autocomplete="street-address" maxlength="500" :required="delivery !== 'pickup'"><small>Обязателен для доставки; для самовывоза не нужен.</small></label>
        <label>Комментарий (необязательно)<textarea name="comment" rows="3" maxlength="1000">{{ old('comment') }}</textarea></label>
        <p>Работаем с юридическими лицами. Предоставляем бухгалтерские документы. Цены указаны с НДС.</p>
        <label class="ah-consent"><input type="checkbox" name="consent" value="1" required @checked(old('consent'))><span>Я согласен с обработкой персональных данных.</span></label>
        <p><strong>Итого за товары: {{ number_format($total / 100, 2, '.', ' ') }} ₸</strong><br>Доставка рассчитывается отдельно; самовывоз — бесплатно.</p>
        <button type="submit" class="ah-button ah-button-orange" :disabled="submitting" x-text="submitting ? 'Оформляем…' : 'Оформить заказ'">Оформить заказ</button>
    </form>
    <aside class="ah-checkout-summary">
        <h2>Ваш заказ</h2>
        @foreach($items as $item)
        <div><strong>{{ $item['product']->name }}</strong><p>SKU: {{ $item['product']->sku }}</p><p>{{ $item['quantity'] }} × {{ number_format((float) $item['product']->price, 2, '.', ' ') }} ₸</p><b>{{ number_format($item['subtotal'] / 100, 2, '.', ' ') }} ₸</b></div>
        @endforeach
        <h2>Итого: {{ number_format($total / 100, 2, '.', ' ') }} ₸</h2>
        <p>Стоимость доставки не включена. Менеджер подтвердит наличие и условия получения.</p>
    </aside>
    </div>
</div>
@endsection
