<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:      __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health:   '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(prepend: [
            \App\Http\Middleware\RemoveTrailingSlash::class,
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\HandleRedirects::class,
            \App\Http\Middleware\SecurityHeadersMiddleware::class,
        ]);
        $middleware->alias([
            'validate.upload' => \App\Http\Middleware\ValidateUploadedFile::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e,
            \Illuminate\Http\Request $request
        ) {
            return response()->view('errors.404', [], 404);
        });
    })
    ->create();
