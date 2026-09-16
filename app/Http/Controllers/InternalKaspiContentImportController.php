<?php

namespace App\Http\Controllers;

use App\Services\Kaspi\KaspiContentRefreshService;
use App\Services\Kaspi\KaspiInternalApiAuthenticator;
use App\Services\Kaspi\KaspiProductionImportService;
use App\Services\Kaspi\KaspiProductionPayloadValidator;
use App\Services\Kaspi\KaspiRefreshPolicy;
use App\Services\Kaspi\KaspiSingleProductPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class InternalKaspiContentImportController extends Controller
{
    public function __invoke(Request $request, KaspiInternalApiAuthenticator $auth, KaspiProductionPayloadValidator $validator, KaspiProductionImportService $service)
    {
        if (! $auth->httpsAllowed($request)) {
            return response()->json(['error' => 'https_required'], 403);
        }
        if (! $auth->authorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        try {
            if ($request->isMethod('GET')) {
                if (array_diff(array_keys($request->query()), ['sku', 'force_content_refresh'])) {
                    throw new \RuntimeException('invalid_query', 422);
                }
                $force = KaspiRefreshPolicy::queryForce($request->query());
                KaspiSingleProductPolicy::assertSku($request->query('sku'));
                $result = $force ? app(KaspiContentRefreshService::class)->preview($request->query('sku')) : $service->preview($request->query('sku'));
            } else {
                if (! $request->isJson()) {
                    throw new \RuntimeException('json_required', 422);
                }
                // Read original JSON: global TrimStrings must not turn a non-exact SKU into the allowed SKU.
                $raw = $request->getContent();
                if (strlen($raw) > 131072) {
                    $oversized = json_decode($raw, true);
                    throw new \RuntimeException(is_array($oversized) && ($oversized['force_content_refresh'] ?? false) === true ? 'payload_too_large' : 'invalid_payload', 422);
                }
                $decoded = json_decode($raw, true);
                if (! is_array($decoded) || array_is_list($decoded)) {
                    throw new \RuntimeException('invalid_payload', 422);
                }
                $payload = $validator->validate($decoded, strlen($raw));
                $result = $service->import($payload);
                Log::info('kaspi_1c_import', ['sku' => $result['sku'], 'status' => $result['status'], 'gallery_added' => $result['gallery_added']]);
            }

            return response()->json($result)->header('Cache-Control', 'private, no-store');
        } catch (ValidationException) {
            return response()->json(['error' => 'invalid_payload'], 422);
        } catch (\Throwable $e) {
            $safe = $e instanceof \RuntimeException && in_array($e->getCode(), [404, 409, 422], true);
            Log::warning('kaspi_1c_failed', ['reason' => $safe ? $e->getMessage() : 'import_failed']);

            return response()->json(['error' => $safe ? $e->getMessage() : 'import_failed'], $safe ? $e->getCode() : 500);
        }
    }
}
