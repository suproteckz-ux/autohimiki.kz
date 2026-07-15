<?php

namespace App\Http\Controllers;

use App\Models\Product;

class ProductController extends Controller
{
    public function show(string $product)
    {
        $product = Product::active()
            ->where('slug', $product)
            ->with(['brand', 'category.parent', 'images'])
            ->firstOrFail();

        $breadcrumbs = [
            ['name' => 'Главная', 'url' => route('home')],
            ['name' => 'Каталог', 'url' => route('catalog')],
        ];

        if ($product->category) {
            if ($product->category->parent) {
                $breadcrumbs[] = [
                    'name' => $product->category->parent->name,
                    'url' => $product->category->parent->url,
                ];
            }

            $breadcrumbs[] = [
                'name' => $product->category->name,
                'url' => $product->category->url,
            ];
        }

        $breadcrumbs[] = ['name' => $product->name];

        $related = $product->category
            ? Product::active()
                ->where('category_id', $product->category_id)
                ->whereKeyNot($product->id)
                ->with(['brand', 'category'])
                ->orderByDesc('is_hit')
                ->orderByDesc('is_new')
                ->orderBy('sort_order')
                ->limit(4)
                ->get()
            : collect();

        return view('pages.product', [
            'product' => $product,
            'breadcrumbs' => $breadcrumbs,
            'related' => $related,
            'canonical' => url("/product/{$product->slug}"),
            'ogImage' => $product->main_image
                ? asset('storage/' . ($product->main_image_webp ?? $product->main_image))
                : asset('img/og-default.jpg'),
            'ogType' => 'product',
        ]);
    }
}
