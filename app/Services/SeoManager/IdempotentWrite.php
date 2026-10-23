<?php

namespace App\Services\SeoManager;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class IdempotentWrite
{
    public function run(Request $request, string $action, Closure $write)
    {
        $key = $request->header('Idempotency-Key', '');
        abort_unless(preg_match('/^[a-zA-Z0-9._:-]{8,128}$/', $key), 422, 'Valid Idempotency-Key required.');
        $actor = $request->attributes->get('seo_actor');
        $hash = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());
        $lookup = fn () => DB::table('seo_manager_requests')->where('actor', $actor)->where('idempotency_key', $key)->first();
        $replay = function ($existing) use ($hash) {
            abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'Idempotency key already used with another request.');
            abort_if($existing->response === null, 409, 'Write in progress; retry.');
            return response()->json(json_decode($existing->response, true), $existing->http_status)->header('Idempotent-Replayed', 'true');
        };
        if ($existing = $lookup()) {
            return $replay($existing);
        }
        try {
            return DB::transaction(function () use ($key, $actor, $hash, $write, $action) {
                $requestId = DB::table('seo_manager_requests')->insertGetId([
                    'actor' => $actor, 'idempotency_key' => $key, 'payload_hash' => $hash,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $result = $write();
                DB::table('seo_manager_requests')->where('id', $requestId)->update(['response' => json_encode($result, JSON_THROW_ON_ERROR)]);
                DB::table('seo_manager_audit_logs')->insert([
                    'actor' => $actor, 'action' => $action, 'resource_id' => $result['id'],
                    'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now(),
                ]);
                return response()->json($result, 201);
            }, 3);
        } catch (QueryException $e) {
            if ($existing = $lookup()) {
                return $replay($existing);
            }
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                abort(409, 'Conflicting concurrent write; refresh and retry.');
            }
            throw $e;
        }
    }
}
