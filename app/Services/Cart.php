<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

class Cart
{
    public function quantities(): array
    {
        return session('cart', []);
    }

    public static function cents(string $price): int
    {
        [$whole, $fraction] = array_pad(explode('.', $price, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function assertAvailable(?Product $product, int $quantity): void
    {
        if (! $product || ! $product->is_active || $quantity < 1 || $quantity > $product->quantity) {
            throw ValidationException::withMessages(['cart' => ($product?->name ?? 'Товар').' — недоступен или остаток изменился. Измените количество или удалите товар из корзины.']);
        }
    }

    public function put(Product $product, int $quantity): void
    {
        $this->assertAvailable($product, $quantity);
        session()->put('cart.'.$product->id, $quantity);
    }

    public function summary(): array
    {
        $quantities = $this->quantities();
        $products = Product::whereIn('id', array_keys($quantities))->get()->keyBy('id');
        $items = [];
        $total = 0;
        $available = count($quantities) > 0;
        foreach ($quantities as $id => $quantity) {
            $product = $products->get($id);
            $valid = $product && $product->is_active && $quantity >= 1 && $quantity <= $product->quantity;
            $subtotal = $product ? self::cents($product->price) * $quantity : 0;
            $items[] = compact('id', 'product', 'quantity', 'valid', 'subtotal');
            $total += $subtotal;
            $available = $available && $valid;
        }

        return compact('items', 'total', 'available');
    }
}
