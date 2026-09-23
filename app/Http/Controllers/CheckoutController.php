<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Services\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function index(Cart $cart)
    {
        $summary = $cart->summary();
        if (! $summary['available']) {
            return redirect()->route('cart.index')->withErrors(['cart' => 'Проверьте наличие и количество товаров в корзине.']);
        }
        if (! session()->has('checkout_token')) {
            session()->put('checkout_token', (string) Str::uuid());
        }

        return response()->view('pages.checkout', $summary)->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Cart $cart)
    {
        $token = $request->input('checkout_token');
        if (is_string($token) && $token === session('completed_checkout_token')) {
            return redirect()->route('checkout.success');
        }
        if (! is_string($token) || ! session('checkout_token') || ! hash_equals(session('checkout_token'), $token)) {
            throw ValidationException::withMessages(['cart' => 'Обновите страницу оформления и попробуйте снова.']);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9 ()\-]+$/', function ($attribute, $value, $fail) {
                $digits = preg_replace('/\D/', '', $value);
                if (strlen($digits) < 10 || strlen($digits) > 15 || preg_match('/^(\d)\1+$/', $digits)) {
                    $fail('Введите корректный телефон, например +7 (700) 123-45-67.');
                }
            }],
            'city' => ['required', 'string', 'min:2', 'max:100'],
            'delivery_method' => ['required', Rule::in(array_keys(Order::DELIVERY))],
            'address' => ['nullable', 'required_unless:delivery_method,pickup', 'string', 'max:500'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'consent' => ['accepted'],
        ], [
            'required' => 'Заполните поле «:attribute».',
            'min' => 'Поле «:attribute» должно содержать не менее :min символов.',
            'max' => 'Поле «:attribute» слишком длинное.',
            'phone.regex' => 'Введите корректный номер телефона.',
            'delivery_method.in' => 'Выберите способ получения из списка.',
            'address.required_unless' => 'Укажите адрес доставки.',
            'consent.accepted' => 'Необходимо согласие на обработку персональных данных.',
        ], ['name' => 'Имя', 'phone' => 'Телефон', 'city' => 'Город', 'delivery_method' => 'Способ получения']);
        unset($data['consent']);
        $digits = preg_replace('/\D/', '', $data['phone']);
        $data['phone'] = '+'.(strlen($digits) === 11 && str_starts_with($digits, '8') ? '7'.substr($digits, 1) : $digits);

        $order = DB::transaction(function () use ($cart, $data, $token) {
            // The unique token is also a database-level guard against duplicate submits.
            if ($existing = Order::where('checkout_token', $token)->first()) {
                return $existing;
            }
            $quantities = $cart->quantities();
            if (! $quantities) {
                throw ValidationException::withMessages(['cart' => 'Корзина пуста. Добавьте товары.']);
            }
            $products = Product::whereIn('id', array_keys($quantities))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $items = [];
            $total = 0;
            foreach ($quantities as $id => $quantity) {
                $product = $products->get($id);
                $cart->assertAvailable($product, (int) $quantity);
                $subtotal = Cart::cents($product->price) * $quantity;
                $items[] = ['product_id' => $id, 'name' => $product->name, 'sku' => $product->sku,
                    'quantity' => $quantity, 'price' => $product->price, 'subtotal' => Cart::decimal($subtotal)];
                $total += $subtotal;
            }
            $order = Order::create($data + ['checkout_token' => $token, 'total' => Cart::decimal($total), 'status' => 'new', 'consented_at' => now()]);
            $order->items()->createMany($items);

            return $order;
        });
        session()->put(['completed_order_id' => $order->id, 'completed_checkout_token' => $token]);
        session()->forget(['cart', 'checkout_token']);

        return redirect()->route('checkout.success');
    }

    public function success()
    {
        abort_unless(session('completed_order_id'), 404);

        return response()->view('pages.order-success', ['order' => Order::findOrFail(session('completed_order_id'))])
            ->header('Cache-Control', 'private, no-store');
    }
}
