<?php

namespace App\Http\Controllers;

use App\Models\Product;

class ProductController extends Controller
{
    public function show(string $product)
    {
        $product = Product::active()
            ->where('slug', $product)
            ->with(['brand', 'category', 'images'])
            ->firstOrFail();

        return view('pages.product', [
            'product' => $product,
            'canonical' => url("/product/{$product->slug}"),
            'ogImage' => $product->main_image
                ? asset('storage/' . ($product->main_image_webp ?? $product->main_image))
                : asset('img/og-default.jpg'),
            'ogType' => 'product',
        ]);
    }
}
