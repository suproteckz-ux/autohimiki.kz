<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SeoFilter;
use App\Models\SeoPage;
use App\Services\SeoManager\FilterProducts;
use App\Services\SeoManager\IdempotentWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SeoManagerController extends Controller
{
    public function status(Request $request)
    {
        return response()->json([
            'status' => 'ready',
            'api_version' => 1,
            'drafts_write' => (bool) $request->attributes->get('seo_can_write'),
        ]);
    }

    public function catalog(Request $request, string $kind)
    {
        $models = ['categories' => Category::class, 'products' => Product::class,
            'pages' => SeoPage::class, 'filters' => SeoFilter::class, 'brands' => Brand::class];
        abort_unless(isset($models[$kind]), 404);
        $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:500']);
        $query = $models[$kind]::query()->orderBy('id');
        if ($kind === 'products') {
            $query->with(['brand:id,name,slug', 'category:id,name,slug,parent_id']);
        } elseif ($kind === 'categories') {
            $query->with('parent:id,slug')->withCount(['products as available_products_count' => fn ($q) => $q->active()->inStock()]);
        } elseif ($kind === 'pages') {
            $query->with(['products:id', 'categories:id']);
        } elseif ($kind === 'filters') {
            $query->with(['brand:id,name,slug', 'category:id,name,slug,parent_id']);
        }
        $paginator = $query->paginate($request->integer('per_page', 200));
        $items = $paginator->getCollection()->map(function ($entity) use ($kind) {
            $fields = ['id', 'name', 'title', 'slug', 'category_id', 'brand_id', 'parent_id', 'is_active',
                'meta_title', 'meta_description', 'h1', 'canonical_url', 'intro', 'seo_text',
                'seo_text_top', 'seo_text_bottom', 'faq', 'updated_at'];
            if ($kind === 'products') {
                $fields = array_merge($fields, ['in_stock', 'attributes', 'short_description', 'description', 'main_image_alt']);
            }
            if ($kind === 'pages') {
                $fields[] = 'filter_criteria';
            }
            if ($kind === 'filters') {
                $fields[] = 'is_indexed';
            }
            $item = array_intersect_key($entity->toArray(), array_flip($fields));
            $item['url'] = in_array($kind, ['products', 'categories'], true) ? $entity->url : url('/'.$entity->slug);
            if (in_array($kind, ['products', 'categories', 'pages'], true) && $entity->canonical_url) {
                $item['url'] = $entity->canonical_url;
            }
            if ($kind === 'brands') {
                $item['url'] = url('/brand/'.$entity->slug);
            }
            if ($kind === 'filters') {
                $item['url'] = $entity->category && $entity->brand ? url('/'.$entity->category->slug.'/'.$entity->brand->slug) : null;
            }
            if ($kind === 'categories') {
                $item['available_products_count'] = $entity->available_products_count;
            }
            if ($kind === 'products') {
                $item['brand'] = $entity->brand?->only(['id', 'name', 'slug']);
            }
            if ($kind === 'pages') {
                $item['product_ids'] = $entity->products->modelKeys();
                $item['category_ids'] = $entity->categories->modelKeys();
                $item['status'] = $entity->is_active ? 'published' : 'draft';
            }

            return $item;
        });

        return response()->json(['data' => $items, 'meta' => ['current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(), 'total' => $paginator->total(), 'per_page' => $paginator->perPage(),
            'generated_at' => now()->toIso8601String()]]);
    }

    public function create(Request $request, IdempotentWrite $writer)
    {
        return $writer->run($request, 'draft.create', fn () => $this->saveDraft($request));
    }

    public function update(Request $request, int $id, IdempotentWrite $writer)
    {
        return $writer->run($request, 'draft.update', fn () => $this->saveDraft($request, $id));
    }

    private function saveDraft(Request $request, ?int $id = null): array
    {
        $page = $id ? SeoPage::whereKey($id)->lockForUpdate()->firstOrFail() : new SeoPage;
        if ($id) {
            abort_if($page->is_active, 409, 'Published pages cannot be changed by this API.');
            abort_unless(DB::table('seo_manager_drafts')->where('seo_page_id', $id)->exists(), 403, 'This draft is not managed by SEO Manager.');
        }
        $allowed = ['kind', 'title', 'slug', 'meta_title', 'meta_description', 'h1', 'intro', 'seo_text', 'faq',
            'product_ids', 'category_ids', 'canonical_url', 'target_queries', 'image_recommendations', 'internal_links', 'filter_criteria'];
        $this->rejectUnknown($request->all(), $allowed);
        $data = $request->validate([
            'kind' => 'sometimes|in:page,filter',
            'title' => 'required|string|max:255', 'slug' => ['required', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('seo_pages')->ignore($id)],
            'meta_title' => 'required|string|max:255', 'meta_description' => 'required|string|max:500',
            'h1' => 'required|string|max:255', 'intro' => 'nullable|string|max:500',
            'seo_text' => 'required|string|max:80000', 'faq' => 'required|array|min:1|max:20',
            'faq.*' => 'array:question,answer', 'faq.*.question' => 'required|string|max:500', 'faq.*.answer' => 'required|string|max:3000',
            'product_ids' => 'required|array|min:1|max:100', 'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->where('is_active', true)->where('in_stock', true)],
            'category_ids' => 'sometimes|array|max:20', 'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('is_active', true)],
            'canonical_url' => 'nullable|url|max:500', 'target_queries' => 'sometimes|array|max:100', 'target_queries.*' => 'string|max:255',
            'image_recommendations' => 'sometimes|array|max:20', 'image_recommendations.*' => 'string|max:1000',
            'internal_links' => 'sometimes|array|max:50', 'internal_links.*' => 'array:url,anchor',
            'internal_links.*.url' => 'required|url|max:500', 'internal_links.*.anchor' => 'required|string|max:255',
            'filter_criteria' => 'nullable|array:category_id,brand_id,attributes',
            'filter_criteria.category_id' => ['required_if:kind,filter', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'filter_criteria.brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('is_active', true)],
            'filter_criteria.attributes' => 'sometimes|array|max:5',
        ]);
        $kind = $data['kind'] ?? 'page';
        $slug = $data['slug'];
        if (in_array($slug, ['admin', 'api', 'catalog', 'product', 'brand', 'blog', 'cart', 'checkout', 'search', 'contacts', 'about', 'delivery', 'payment', 'robots-txt'], true)
            || Category::where('slug', $slug)->exists() || Product::where('slug', $slug)->exists() || Brand::where('slug', $slug)->exists() || SeoFilter::where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => 'URL conflicts with an existing catalog entity or reserved route.']);
        }
        if (SeoPage::whereRaw('LOWER(title) = ?', [mb_strtolower($data['title'])])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            throw ValidationException::withMessages(['title' => 'An SEO page with this title already exists.']);
        }
        $canonical = $data['canonical_url'] ?? url('/'.$slug);
        $this->validateInternalUrl($canonical);
        if (rtrim($canonical, '/') !== 'https://autohimiki.kz/'.$slug) {
            throw ValidationException::withMessages(['canonical_url' => 'Canonical must match the stable landing URL.']);
        }
        foreach ($data['internal_links'] ?? [] as $link) {
            $this->validateInternalUrl($link['url']);
        }
        $criteria = $kind === 'filter' ? ($data['filter_criteria'] ?? []) : null;
        if ($kind === 'filter') {
            if (empty($criteria['brand_id']) && empty($criteria['attributes'])) {
                throw ValidationException::withMessages(['filter_criteria' => 'A category alone already has its own URL.']);
            }
            foreach ($criteria['attributes'] ?? [] as $key => $value) {
                if (! preg_match('/^[\pL\pN _-]{1,80}$/u', (string) $key) || ! is_scalar($value) || mb_strlen((string) $value) > 100) {
                    throw ValidationException::withMessages(['filter_criteria' => 'Invalid attribute key/value. Use actual scalar catalog attributes.']);
                }
            }
            $matched = app(FilterProducts::class)->query($criteria)->pluck('id');
            if ($matched->isEmpty() || collect($data['product_ids'])->diff($matched)->isNotEmpty()) {
                throw ValidationException::withMessages(['filter_criteria' => 'No matching available products, or selected products do not match.']);
            }
            if ($matched->count() === Product::active()->inStock()->where('category_id', $criteria['category_id'])->count()) {
                throw ValidationException::withMessages(['filter_criteria' => 'This filter duplicates the entire available category.']);
            }
            if (empty($criteria['attributes']) && SeoFilter::where('category_id', $criteria['category_id'])->where('brand_id', $criteria['brand_id'])->exists()) {
                throw ValidationException::withMessages(['filter_criteria' => 'This category/brand landing page already exists.']);
            }
            foreach (SeoPage::whereNotNull('filter_criteria')->when($id, fn ($q) => $q->where('id', '!=', $id))->get() as $existing) {
                if ($existing->filter_criteria == $criteria) {
                    throw ValidationException::withMessages(['filter_criteria' => 'This filter landing page already exists.']);
                }
            }
        }
        $fields = array_intersect_key($data, array_flip(['title', 'slug', 'meta_title', 'meta_description', 'h1', 'intro', 'seo_text', 'faq']));
        foreach (['title', 'meta_title', 'meta_description', 'h1', 'intro'] as $field) {
            if (isset($fields[$field])) {
                $fields[$field] = strip_tags($fields[$field]);
            }
        }
        $fields['seo_text'] = Str::sanitizeHtml($data['seo_text']);
        foreach ($fields['faq'] as &$entry) {
            $entry = array_map(fn ($v) => strip_tags($v), $entry);
        }
        unset($entry);
        $page->fill($fields);
        $page->is_active = false;
        $page->canonical_url = $canonical;
        $page->filter_criteria = $criteria;
        $page->save();
        $page->products()->sync($data['product_ids']);
        $page->categories()->sync($data['category_ids'] ?? []);
        DB::table('seo_manager_drafts')->updateOrInsert(['seo_page_id' => $page->id], [
            'kind' => $kind, 'metadata' => json_encode(array_intersect_key($data, array_flip(['target_queries', 'image_recommendations', 'internal_links'])), JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $page->id, 'kind' => $kind, 'status' => 'draft', 'url' => url('/'.$page->slug), 'admin_url' => url('/admin/seo-pages/'.$page->id.'/edit')];
    }

    public function proposal(Request $request, IdempotentWrite $writer)
    {
        return $writer->run($request, 'proposal.create', function () use ($request) {
            $this->rejectUnknown($request->all(), ['target_type', 'target_id', 'proposal']);
            $data = $request->validate([
                'target_type' => 'required|in:product,category,page', 'target_id' => 'required|integer|min:1',
                'proposal' => 'required|array:meta_title,meta_description,h1,seo_text,reason',
                'proposal.meta_title' => 'sometimes|string|max:255', 'proposal.meta_description' => 'sometimes|string|max:500',
                'proposal.h1' => 'sometimes|string|max:255', 'proposal.seo_text' => 'sometimes|string|max:80000',
                'proposal.reason' => 'required|string|max:4000',
            ]);
            $model = ['product' => Product::class, 'category' => Category::class, 'page' => SeoPage::class][$data['target_type']];
            abort_unless($model::whereKey($data['target_id'])->exists(), 422, 'Unknown target.');
            foreach ($data['proposal'] as $key => &$value) {
                $value = $key === 'seo_text' ? Str::sanitizeHtml($value) : strip_tags($value);
            }
            unset($value);
            $id = DB::table('seo_manager_proposals')->insertGetId([
                'target_type' => $data['target_type'], 'target_id' => $data['target_id'],
                'proposal' => json_encode($data['proposal'], JSON_THROW_ON_ERROR),
                'status' => 'proposed', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['id' => $id, 'kind' => 'proposal', 'status' => 'proposed', 'admin_url' => url('/admin/seo-manager-proposals')];
        });
    }

    private function rejectUnknown(array $data, array $allowed): void
    {
        if ($extra = array_diff(array_keys($data), $allowed)) {
            throw ValidationException::withMessages(['payload' => 'Unsupported fields: '.implode(', ', $extra)]);
        }
    }

    private function validateInternalUrl(string $url): void
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'autohimiki.kz'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw ValidationException::withMessages(['url' => 'Use an HTTPS autohimiki.kz URL without parameters.']);
        }
    }
}
