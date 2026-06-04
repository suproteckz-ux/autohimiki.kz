<?php
namespace App\Http\Middleware;
use App\Services\CacheService;
use Closure;
use Illuminate\Http\Request;
class HandleRedirects
{
    public function handle(Request $request, Closure $next)
    {
        $path = '/' . ltrim($request->getPathInfo(), '/');
        $redirects = CacheService::redirects();
        if (isset($redirects[$path])) {
            return redirect($redirects[$path], 301);
        }
        return $next($request);
    }
}
