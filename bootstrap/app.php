<?php

use App\Http\Middleware\HandleRedirects;
use App\Http\Middleware\RemoveTrailingSlash;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\ValidateUploadedFile;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(prepend: [
            RemoveTrailingSlash::class,
        ]);
        $middleware->web(append: [
            HandleRedirects::class,
            SecurityHeadersMiddleware::class,
        ]);
        $middleware->alias([
            'validate.upload' => ValidateUploadedFile::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (
            NotFoundHttpException $e,
            Request $request
        ) {
            return response()->view('errors.404', [], 404);
        });
    })
    ->create();
