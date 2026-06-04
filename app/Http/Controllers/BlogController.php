<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use Illuminate\Http\Request;

class BlogController extends Controller
{
    // ──────────────────────────────────────────────────────────────
    // /blog — список статей
    // ──────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $posts = BlogPost::active()
            ->latest('published_at')
            ->paginate(12)
            ->withQueryString();

        $currentPage = (int) $request->get('page', 1);
        $baseUrl     = url('/blog');

        // Canonical:
        // стр.1 → /blog (без ?page=1)
        // стр.2+ → /blog?page=N (на себя)
        $canonical = $currentPage > 1
            ? $posts->url($currentPage)
            : $baseUrl;

        // Мета-данные с учётом страницы пагинации
        $metaTitle = $currentPage > 1
            ? "Блог об автохимии — страница {$currentPage} | Autohimiki.kz"
            : 'Блог об автохимии — советы и обзоры | Autohimiki.kz';

        $metaDesc = 'Полезные статьи об уходе за автомобилем. Как выбрать автошампунь, '
            . 'что такое антидождь, обзоры брендов Koch Chemie, Meguiar\'s, Shine Systems.';

        return view('pages.blog', compact(
            'posts',
            'canonical',
            'currentPage',
            'metaTitle',
            'metaDesc'
        ));
    }

    // ──────────────────────────────────────────────────────────────
    // /blog/{slug} — страница статьи
    // ──────────────────────────────────────────────────────────────

    public function show(string $slug)
    {
        $post = BlogPost::active()
            ->where('slug', $slug)
            ->with([
                'products' => fn ($q) => $q->active()
                    ->with('brand:id,name,slug')
                    ->select('products.id', 'products.name', 'products.slug',
                             'products.price', 'products.old_price', 'products.main_image',
                             'products.main_image_webp', 'products.main_image_alt',
                             'products.in_stock', 'products.is_hit', 'products.is_new',
                             'products.brand_id', 'products.category_id'),
            ])
            ->first();

        if (! $post) {
            abort(404);
        }

        // Недавние статьи для блока «Читайте также»
        $recent = BlogPost::active()
            ->where('id', '!=', $post->id)
            ->latest('published_at')
            ->limit(4)
            ->get(['id', 'title', 'slug', 'cover_image', 'cover_image_webp', 'published_at']);

        // og:image для шаблона
        $ogImage = $post->cover_image
            ? asset('storage/' . $post->cover_image)
            : asset('img/og-default.jpg');

        $ogType = 'article';

        return view('pages.blog-post', compact(
            'post',
            'recent',
            'ogImage',
            'ogType'
        ));
    }
}
