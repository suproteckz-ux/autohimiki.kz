<?php

namespace App\Services\Kaspi;

use Illuminate\Http\Request;

class KaspiInternalApiAuthenticator
{
    public function authorized(Request $request): bool
    {
        $expected = (string) config('services.kaspi.internal_api_token');
        $provided = (string) $request->bearerToken();

        return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }

    public function httpsAllowed(Request $request): bool
    {
        // Honor only Symfony/Laravel trusted-proxy handling, never raw forwarded headers.
        return ! app()->isProduction() || $request->isSecure();
    }
}
