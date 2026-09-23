<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Cart;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function index(Cart $cart)
    {
        return response()->view('pages.cart', $cart->summary())->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Cart $cart)
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id']);
        $product = Product::findOrFail($data['product_id']);
        $cart->put($product, ($cart->quantities()[$product->id] ?? 0) + 1);

        return back()->with('cart_added', 'Товар добавлен в корзину.');
    }

    public function update(Request $request, int $id, Cart $cart)
    {
        $data = $request->validate(['quantity' => 'required|integer|min:1|max:2147483647'], [
            'quantity.*' => 'Укажите целое количество от 1 до доступного остатка.',
        ]);
        abort_unless(array_key_exists($id, $cart->quantities()), 404);
        $cart->put(Product::findOrFail($id), (int) $data['quantity']);

        return redirect()->route('cart.index');
    }

    public function destroy(int $id)
    {
        session()->forget('cart.'.$id);

        return redirect()->route('cart.index');
    }
}
