<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    private const MIN_QUERY_LENGTH = 2;
    private const MAX_QUERY_LENGTH = 100;
    private const PER_PAGE         = 24;

    public function index(Request $request)
    {
        $query = mb_substr(trim($request->get('q', '')), 0, self::MAX_QUERY_LENGTH);

        $products = collect();

        if (mb_strlen($query) >= self::MIN_QUERY_LENGTH) {
            $products = $this->search($query);
        }

        return response()
            ->view('pages.search', compact('query', 'products'))
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function search(string $query): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        // Определяем стратегию поиска
        // FULLTEXT работает только при длине слова >= 3 символов (по умолчанию MySQL)
        $useFulltext = mb_strlen($query) >= 3 && $this->isFulltextAvailable();

        $dbQuery = Product::active()
            ->with([
                'brand:id,name,slug',
                'category:id,name,slug',
            ])
            ->select([
                'id', 'name', 'slug', 'sku', 'price', 'old_price',
                'main_image', 'main_image_webp', 'main_image_alt',
                'in_stock', 'is_hit', 'is_new', 'brand_id', 'category_id',
            ]);

        if ($useFulltext) {
            // FULLTEXT поиск — быстрее на больших таблицах, учитывает релевантность
            $escaped = $this->escapeFulltext($query);
            $dbQuery
                ->whereRaw(
                    'MATCH(name, sku, short_description) AGAINST(? IN BOOLEAN MODE)',
                    ["+{$escaped}*"]
                )
                ->orderByRaw(
                    'MATCH(name, sku, short_description) AGAINST(? IN BOOLEAN MODE) DESC',
                    ["+{$escaped}*"]
                );
        } else {
            // Fallback на LIKE для коротких запросов или если FULLTEXT недоступен
            $dbQuery
                ->where(function ($q) use ($query) {
                    $q->where('name', 'like', "%{$query}%")
                      ->orWhere('sku', 'like', "%{$query}%")
                      ->orWhere('short_description', 'like', "%{$query}%");
                })
                ->orderByDesc('is_hit')
                ->orderByDesc('views');
        }

        return $dbQuery->paginate(self::PER_PAGE)->withQueryString();
    }

    /**
     * Экранирование спецсимволов FULLTEXT BOOLEAN MODE.
     */
    private function escapeFulltext(string $query): string
    {
        // Убираем спецсимволы FULLTEXT boolean mode
        $special = ['+', '-', '<', '>', '(', ')', '~', '*', '"', '@'];
        return str_replace($special, ' ', $query);
    }

    /**
     * Проверяем доступность FULLTEXT индекса.
     * Кэшируем результат, чтобы не проверять каждый запрос.
     */
    private function isFulltextAvailable(): bool
    {
        return \Cache::remember('fulltext_available', 86400, function () {
            try {
                $result = \DB::select(
                    "SHOW INDEX FROM products WHERE Key_name = 'idx_products_fulltext'"
                );
                return !empty($result);
            } catch (\Throwable) {
                return false;
            }
        });
    }
}
