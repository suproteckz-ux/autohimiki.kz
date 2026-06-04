<?php

namespace App\Http\Controllers;

use App\Services\CacheService;

class HomeController extends Controller
{
    public function index()
    {
        // Все данные берём из кэша — 0 запросов к БД при прогретом кэше
        $categories  = CacheService::homepageCategories();
        $hits        = CacheService::homepageHits();
        $newProducts = CacheService::homepageNewProducts();
        $brands      = CacheService::homepageBrands();

        return view('pages.home', compact(
            'categories', 'hits', 'newProducts', 'brands'
        ));
    }
}
