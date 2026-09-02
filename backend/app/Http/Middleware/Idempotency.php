<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stripe-style request-level idempotency (F-4).
 *
 * When a request carries an `Idempotency-Key` header, the first response for
 * (key, method, path) is stored and replayed for every retry — so a client
 * that resubmits after a timeout can never make the handler post a second
 * time. Without the header the middleware is a no-op (behaviour unchanged).
 *
 * - unseen key            -> claim it, run the handler, store the response
 * - key seen, completed   -> replay the stored status + body
 * - key seen, in-flight   -> 409 (a request with this key is still running)
 * - key reused with a different body -> 422 (keys must not be recycled)
 *
 * Only successful (2xx) responses are recorded — a failed attempt can be
 * retried with the same key. Records older than 24h are ignored and swept.
 */
class Idempotency
{
    private const TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '' || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $method = $request->method();
        $path = '/' . ltrim($request->path(), '/');
        $fingerprint = hash('sha256', $request->getContent() ?: '');
        $cutoff = now()->subHours(self::TTL_HOURS);

        DB::table('idempotency_keys')->where('created_at', '<', $cutoff)->delete();

        $existing = DB::table('idempotency_keys')
            ->where('idempotency_key', $key)->where('method', $method)->where('path', $path)
            ->where('created_at', '>=', $cutoff)
            ->first();

        if ($existing) {
            if ($existing->request_fingerprint && $existing->request_fingerprint !== $fingerprint) {
                return response()->json([
                    'message' => 'This Idempotency-Key was already used with a different request body.',
                ], 422);
            }
            if ($existing->completed_at === null) {
                return response()->json([
                    'message' => 'A request with this Idempotency-Key is already being processed.',
                ], 409);
            }

            return response(
                $existing->response_body,
                $existing->response_status,
            )->header('Content-Type', 'application/json')->header('Idempotency-Replayed', 'true');
        }

        // Claim the key. A race on the unique index means someone else claimed it first.
        try {
            DB::table('idempotency_keys')->insert([
                'idempotency_key' => $key, 'method' => $method, 'path' => $path,
                'request_fingerprint' => $fingerprint, 'locked_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'message' => 'A request with this Idempotency-Key is already being processed.',
            ], 409);
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            DB::table('idempotency_keys')
                ->where('idempotency_key', $key)->where('method', $method)->where('path', $path)
                ->update([
                    'response_status' => $response->getStatusCode(),
                    'response_body' => $response->getContent(),
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
        } else {
            // Let a failed attempt be retried with the same key.
            DB::table('idempotency_keys')
                ->where('idempotency_key', $key)->where('method', $method)->where('path', $path)
                ->whereNull('completed_at')
                ->delete();
        }

        return $response;
    }
}
