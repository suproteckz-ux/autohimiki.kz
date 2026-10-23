<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SeoManagerAccess
{
    public function handle(Request $request, Closure $next, string $scope)
    {
        $local = app()->environment(['local', 'testing'])
            && config('seo_manager.allow_local_http')
            && in_array($request->getHost(), ['localhost', '127.0.0.1', '::1'], true);
        abort_unless($request->isSecure() || $local, 403, 'HTTPS required.');
        abort_if(strlen($request->getContent()) > 262144, 413, 'Payload too large.');
        $token = $request->bearerToken() ?? '';
        abort_if(strlen($token) < 32 || strlen($token) > 256, 401, 'Invalid API token.');
        $hash = hash('sha256', $token);
        $writer = strtolower(trim((string) config('seo_manager.write_token_hash')));
        $reader = strtolower(trim((string) config('seo_manager.read_token_hash')));
        $validHash = fn (string $value): bool => preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
        $canWrite = $validHash($writer) && hash_equals($writer, $hash);
        $canRead = $canWrite || ($validHash($reader) && hash_equals($reader, $hash));
        abort_unless($canRead, 401, 'Invalid API token.');
        abort_if($scope === 'drafts:write' && ! $canWrite, 403, 'Missing drafts:write scope.');
        $request->attributes->set('seo_actor', substr($hash, 0, 24));
        $request->attributes->set('seo_can_write', $canWrite);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
