<?php

use App\Http\Controllers\InternalKaspiContentCandidatesController;
use Illuminate\Support\Facades\Route;

Route::get('/internal/kaspi-content/candidates', InternalKaspiContentCandidatesController::class)
    ->middleware('throttle:kaspi-candidates');
