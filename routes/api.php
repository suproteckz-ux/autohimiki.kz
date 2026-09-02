<?php

use App\Http\Controllers\InternalKaspiContentCandidatesController;
use App\Http\Controllers\InternalKaspiContentImportController;
use Illuminate\Support\Facades\Route;

Route::get('/internal/kaspi-content/candidates', InternalKaspiContentCandidatesController::class)
    ->middleware('throttle:kaspi-candidates');

Route::match(['get', 'post'], '/internal/kaspi-content/import', InternalKaspiContentImportController::class)->middleware('throttle:kaspi-import');
