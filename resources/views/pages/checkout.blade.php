@extends('layouts.app')
@section('title', 'Оформление заказа — Autohimiki.kz')
@section('noindex', '1')
@section('content')
@php
    $fmtMoney = static function(float $v): string {
        return number_format($v, fmod($v, 1.0) < 0.001 ? 0 : 2, '.', ' ');
    };
@endphp
<div class="ah-container ah-commerce">
    <h1>Оформление заказа</h1>
    <a href="{{ route('cart.index') }}">← Вернуться в корзину</a>
    @include('components.ui.cart-feedback')

    @if($errors->any())
    <div class="ah-form-errors" role="alert">
        <strong>Пожалуйста, исправьте ошибки:</strong>
        <ul>
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <div class="ah-checkout-grid">
    <form method="POST" action="{{ route('checkout.store') }}" class="ah-checkout-form"
          x-data="{ delivery: {{ \Illuminate\Support\Js::from(old('delivery_method', 'pickup')) }}, submitting: false }"
          @submit="submitting = true">
        @csrf
        <input type="hidden" name="checkout_token" value="{{ session('checkout_token') }}">

        <label>Имя
            <input name="name" value="{{ old('name') }}" autocomplete="given-name"
                   required minlength="2" maxlength="100"
                   @if($errors->has('name')) aria-invalid="true" @endif>
            @error('name')<span class="ah-field-error">{{ $message }}</span>@enderror
        </label>

        <label>Телефон
            <input name="phone" type="tel" inputmode="tel" value="{{ old('phone') }}"
                   autocomplete="tel" required maxlength="32" placeholder="+7 (700) 123-45-67"
                   @if($errors->has('phone')) aria-invalid="true" @endif>
            @error('phone')<span class="ah-field-error">{{ $message }}</span>@enderror
        </label>

        <label>Город
            <input name="city" value="{{ old('city') }}" autocomplete="address-level2"
                   required minlength="2" maxlength="100"
                   @if($errors->has('city')) aria-invalid="true" @endif>
            @error('city')<span class="ah-field-error">{{ $message }}</span>@enderror
        </label>

        <fieldset>
            <legend style="font-size:14px;font-weight:600;color:var(--ah-ink);margin-bottom:8px">Способ получения</legend>
            <div class="ah-delivery-cards">
                @php
                    $deliveryIcons = [
                        'pickup'  => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
                        'yandex'  => '<rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
                        'kazpost' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>',
                    ];
                @endphp
                @foreach(\App\Models\Order::DELIVERY as $value => $deliveryLabel)
                <div class="ah-delivery-card">
                    <input type="radio" name="delivery_method" id="delivery_{{ $value }}"
                           value="{{ $value }}" x-model="delivery"
                           @if(old('delivery_method', 'pickup') === $value) checked @endif
                           required>
                    <label for="delivery_{{ $value }}" class="ah-delivery-card__label">
                        <span class="ah-delivery-card__icon" aria-hidden="true">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round">
                                {!! $deliveryIcons[$value] ?? '<circle cx="12" cy="12" r="10"/>' !!}
                            </svg>
                        </span>
                        <span class="ah-delivery-card__info">
                            <span class="ah-delivery-card__title">{{ $deliveryLabel }}</span>
                            @if($value === 'pickup')
                                <span class="ah-delivery-card__note">Самовывоз — бесплатно</span>
                            @elseif($value === 'yandex')
                                <span class="ah-delivery-card__note">По тарифам Яндекс Доставки</span>
                            @elseif($value === 'kazpost')
                                <span class="ah-delivery-card__note">Отправка по Казахстану</span>
                            @endif
                        </span>
                    </label>
                </div>
                @endforeach
            </div>
            @error('delivery_method')<span class="ah-field-error">{{ $message }}</span>@enderror
        </fieldset>

        <label x-show="delivery !== 'pickup'">Адрес доставки
            <input name="address" value="{{ old('address') }}" autocomplete="street-address"
                   maxlength="500" :required="delivery !== 'pickup'"
                   @if($errors->has('address')) aria-invalid="true" @endif>
            <small>Обязателен для доставки; для самовывоза не нужен.</small>
            @error('address')<span class="ah-field-error">{{ $message }}</span>@enderror
        </label>

        <label>Комментарий (необязательно)
            <textarea name="comment" rows="3" maxlength="1000">{{ old('comment') }}</textarea>
        </label>

        <p>Работаем с юридическими лицами. Предоставляем бухгалтерские документы. Цены указаны с НДС.</p>

        <label class="ah-consent">
            <input type="checkbox" name="consent" value="1" required @checked(old('consent'))>
            <span>Я согласен с обработкой персональных данных.</span>
        </label>
        @error('consent')<span class="ah-field-error">{{ $message }}</span>@enderror

        <p><strong>Итого за товары: {{ $fmtMoney($total / 100) }} ₸</strong><br>Доставка рассчитывается отдельно; самовывоз — бесплатно.</p>

        <button type="submit" class="ah-button ah-button-orange"
                :disabled="submitting"
                x-text="submitting ? 'Оформляем…' : 'Оформить заказ'">Оформить заказ</button>
    </form>

    <aside class="ah-checkout-summary">
        <h2>Ваш заказ</h2>
        @foreach($items as $item)
        <div>
            <strong>{{ $item['product']->name }}</strong>
            <p>SKU: {{ $item['product']->sku }}</p>
            <p>{{ $item['quantity'] }} × {{ $fmtMoney((float) $item['product']->price) }} ₸</p>
            <b>{{ $fmtMoney($item['subtotal'] / 100) }} ₸</b>
        </div>
        @endforeach
        <h2>Итого: {{ $fmtMoney($total / 100) }} ₸</h2>
        <p>Стоимость доставки не включена. Менеджер подтвердит наличие и условия получения.</p>
    </aside>
    </div>
</div>
@endsection
