<?php

namespace App\Http\Controllers;

use App\Services\Kaspi\KaspiInternalApiAuthenticator;
use App\Services\Kaspi\KaspiProductionCandidateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InternalKaspiContentCandidatesController extends Controller
{
    public function __invoke(Request $request, KaspiInternalApiAuthenticator $auth, KaspiProductionCandidateService $service): JsonResponse
    {
        if (! $auth->httpsAllowed($request)) {
            return response()->json(['error' => 'https_required'], 403);
        }
        if (! $auth->authorized($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $validator = Validator::make($request->query(), [
            'sku' => ['sometimes', 'required', 'string', 'max:255'],
            'limit' => ['sometimes', 'required', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'required', 'integer', 'min:0'],
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => 'invalid_query', 'fields' => $validator->errors()], 422);
        }

        return response()->json($service->list($validator->validated()))->header('Cache-Control', 'private, no-store');
    }
}
