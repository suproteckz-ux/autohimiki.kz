@if(session('cart_added'))
<div class="ah-cart-feedback" role="status">{{ session('cart_added') }} <a href="{{ route('cart.index') }}">Перейти в корзину</a> · <a href="{{ route('catalog') }}">Продолжить покупки</a></div>
@endif
@if($errors->any())
<div class="ah-cart-error ah-cart-feedback" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
