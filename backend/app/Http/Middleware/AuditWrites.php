<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuditWrites
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next)
    {
        /** @var Response $response */
        $response = $next($request);

        if (!in_array($request->method(), self::WRITE_METHODS, true)) {
            return $response;
        }

        // Only audit successful state changes
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return $response;
        }

        Log::channel('audit')->info('write', [
            'request_id' => $request->attributes->get('request_id'),
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $status,
            'user_id' => optional($request->user())->id,
            'user_email' => optional($request->user())->email,
            'ip' => $request->ip(),
        ]);

        $this->record($request, $status);

        return $response;
    }

    /** Durable copy of the audit line (the log file can be rotated or deleted). A failure here must never break the request. */
    private function record(Request $request, int $status): void
    {
        try {
            $payload = $request->except(['password', 'password_confirmation', 'current_password', 'new_password', 'token', 'confirm_phrase']);
            $json = json_encode($payload, JSON_PARTIAL_OUTPUT_ON_ERROR);
            DB::table('audit_events')->insert([
                'request_id' => $request->attributes->get('request_id'),
                'user_id' => optional($request->user())->id,
                'method' => $request->method(),
                'path' => substr($request->path(), 0, 255),
                'status' => $status,
                'ip' => $request->ip(),
                'payload' => $json !== false && strlen($json) <= 60000 ? $json : json_encode(['truncated' => true]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('audit_events insert failed: ' . $e->getMessage());
        }
    }
}
