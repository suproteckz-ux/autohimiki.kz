<?php

use App\Http\Controllers\InternalKaspiContentCandidatesController;
use App\Http\Controllers\InternalKaspiContentImportController;
use Illuminate\Support\Facades\Route;

Route::get('/internal/kaspi-content/candidates', InternalKaspiContentCandidatesController::class)
    ->middleware('throttle:kaspi-candidates');

Route::match(['get', 'post'], '/internal/kaspi-content/import', InternalKaspiContentImportController::class)->middleware('throttle:kaspi-import');

Route::prefix('seo-manager/v1')->middleware('throttle:60,1')->group(function () {
    Route::get('/status', [\App\Http\Controllers\SeoManagerController::class, 'status'])
        ->middleware(\App\Http\Middleware\SeoManagerAccess::class.':catalog:read');
    Route::get('/catalog/{kind}', [\App\Http\Controllers\SeoManagerController::class, 'catalog'])
        ->middleware(\App\Http\Middleware\SeoManagerAccess::class.':catalog:read');
    Route::middleware(\App\Http\Middleware\SeoManagerAccess::class.':drafts:write')->group(function () {
        Route::post('/drafts', [\App\Http\Controllers\SeoManagerController::class, 'create']);
        Route::put('/drafts/{id}', [\App\Http\Controllers\SeoManagerController::class, 'update'])->whereNumber('id');
        Route::post('/proposals', [\App\Http\Controllers\SeoManagerController::class, 'proposal']);
    });
});
