<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\SeoPage;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class BlogController extends Controller
{
    // ──────────────────────────────────────────────────────────────
    // /blog — unified listing: blog posts + SEO pages flagged for blog
    // ──────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $perPage     = 12;
        $currentPage = max(1, (int) $request->get('page', 1));

        // Regular blog posts
        $blogPosts = BlogPost::active()
            ->latest('published_at')
            ->get(['id', 'title', 'slug', 'cover_image', 'cover_image_webp',
                   'cover_image_alt', 'content', 'published_at']);

        // SEO pages the admin marked for the blog
        $seoPages = SeoPage::active()
            ->where('show_in_blog', true)
            ->latest('updated_at')
            ->get(['id', 'title', 'slug', 'hero_image', 'hero_label', 'intro', 'updated_at']);

        // Normalize both to stdClass items for the unified template
        $all = collect();

        foreach ($blogPosts as $p) {
            $intro = $p->content
                ? Str::limit(strip_tags($p->content), 150)
                : null;
            $all->push((object) [
                'type'      => 'blog',
                'title'     => $p->title,
                'url'       => route('blog.show', $p->slug),
                'image'     => $p->cover_image
                    ? asset('storage/' . ($p->cover_image_webp ?? $p->cover_image))
                    : null,
                'image_alt' => $p->cover_image_alt ?? $p->title,
                'label'     => null,
                'intro'     => $intro,
                'date'      => $p->published_at,
            ]);
        }

        foreach ($seoPages as $p) {
            $all->push((object) [
                'type'      => 'seo',
                'title'     => $p->title,
                'url'       => url('/' . $p->slug),
                'image'     => $p->hero_image ? asset('storage/' . $p->hero_image) : null,
                'image_alt' => $p->title,
                'label'     => $p->hero_label,
                'intro'     => $p->intro,
                'date'      => $p->updated_at,
            ]);
        }

        // Sort descending by date; nulls go to the end
        $all = $all->sortByDesc(fn ($item) => optional($item->date)->timestamp ?? 0)->values();

        $total  = $all->count();
        $sliced = $all->forPage($currentPage, $perPage);

        $posts = new LengthAwarePaginator(
            $sliced,
            $total,
            $perPage,
            $currentPage,
            ['path' => url('/blog'), 'query' => $request->query()]
        );

        $baseUrl   = url('/blog');
        $canonical = $currentPage > 1 ? $posts->url($currentPage) : $baseUrl;

        $metaTitle = $currentPage > 1
            ? "Блог об автохимии — страница {$currentPage} | Autohimiki.kz"
            : 'Блог об автохимии — советы и обзоры | Autohimiki.kz';

        $metaDesc = 'Полезные советы по выбору автохимии, уходу за автомобилем и сезонной подготовке. '
            . 'Обзоры средств, инструкции и рекомендации специалистов Autohimiki.kz.';

        return view('pages.blog', compact(
            'posts', 'canonical', 'currentPage', 'metaTitle', 'metaDesc'
        ));
    }

    // ──────────────────────────────────────────────────────────────
    // /blog/{slug} — individual blog post (BlogPost only)
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

        // Recent posts for "Читайте также"
        $recent = BlogPost::active()
            ->where('id', '!=', $post->id)
            ->latest('published_at')
            ->limit(4)
            ->get(['id', 'title', 'slug', 'cover_image', 'cover_image_webp', 'published_at']);

        $ogImage = $post->cover_image
            ? asset('storage/' . $post->cover_image)
            : asset('img/og-default.jpg');

        $ogType = 'article';

        return view('pages.blog-post', compact(
            'post', 'recent', 'ogImage', 'ogType'
        ));
    }
}
