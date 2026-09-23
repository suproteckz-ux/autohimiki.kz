@extends('layouts.app')
@section('title', 'Заказ принят — Autohimiki.kz')
@section('noindex', '1')
@section('content')
<div class="ah-container ah-commerce">
    <h1>Спасибо! Заказ №{{ $order->id }} принят.</h1>
    <p>Мы свяжемся с вами для подтверждения заказа и доставки.</p>
    <p>Сумма товаров: {{ number_format((float) $order->total, 2, '.', ' ') }} ₸</p>
    <a href="{{ route('catalog') }}" class="ah-button ah-button-orange">Продолжить покупки</a>
</div>
@endsection
