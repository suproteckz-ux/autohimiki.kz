<?php
namespace App\Http\Controllers;
use App\Models\Brand;
use App\Models\Product;
use App\Services\CacheService;
use Illuminate\Http\Request;
class BrandController extends Controller
{
    public function index()
    {
        $brands = CacheService::allBrands();
        return view('pages.brands', compact('brands'));
    }
    public function show(Request $request, string $slug)
    {
        $brand = Brand::active()->where('slug', $slug)->firstOrFail();
        $sort = $request->get('sort', 'default');
        $query = Product::active()->where('brand_id', $brand->id)->with('category');
        match ($sort) {
            'price_asc'  => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'new'        => $query->latest(),
            default      => $query->orderBy('sort_order'),
        };
        $currentPage = (int) $request->get('page', 1);
        $products = $query->paginate(24)->withQueryString();
        $baseUrl = url("/brand/{$brand->slug}");
        $noindex = $sort !== 'default';
        $canonical = ($currentPage > 1 && !$noindex) ? $products->url($currentPage) : $baseUrl;
        $ogImage = $brand->logo ? asset('storage/' . $brand->logo) : asset('img/og-default.jpg');
        return view('pages.brand', compact('brand', 'products', 'sort', 'canonical', 'noindex', 'currentPage', 'ogImage'));
    }
}
