<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontReport = [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
    ];

    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
        'token',
        'api_token',
        'secret',
    ];

    public function register()
    {
        $this->reportable(function (Throwable $e) {
            // request_id ties this full-detail log line back to the
            // short reference number the client gets in its JSON response
            // (see jsonError()) and to the matching row in the 'requests'
            // channel — that's how a raw SQL/500 error gets traced from a
            // support ticket back to its actual cause without ever putting
            // SQL in front of the user.
            Log::channel('errors')->error($e->getMessage(), [
                'request_id' => request()->attributes->get('request_id'),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => collect($e->getTrace())->take(15)->all(),
            ]);
        });

        $this->renderable(function (Throwable $e, $request) {
            if (!$request->is('api/*') && !$request->expectsJson()) {
                return null;
            }

            return $this->jsonError($e);
        });
    }

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            Log::channel('security')->info('unauthenticated', [
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect()->guest(route('login'));
    }

    private function jsonError(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], 422);
        }

        if ($e instanceof AuthenticationException) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }

        if ($e instanceof AuthorizationException) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        if ($e instanceof HttpExceptionInterface) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Request failed.',
            ], $e->getStatusCode());
        }

        // Never let raw SQL back onto an API response, even with app.debug
        // on — a customer/user reading the response has no use for column
        // names or query text. The 'reference' below is the request_id also
        // written next to this exception's full message/file/line/trace in
        // storage/logs/errors-*.log (see reportable() above) — grep it there
        // to see the actual SQL error instead of exposing it to the client.
        $reference = request()->attributes->get('request_id');

        if ($e instanceof QueryException) {
            $sqlState = $e->errorInfo[0] ?? null;
            $driverCode = $e->errorInfo[1] ?? null;

            if ($sqlState === '23000' && $driverCode === 1062) {
                return response()->json(['message' => 'This record already exists. Please refresh and try again.', 'reference' => $reference], 409);
            }
            if ($sqlState === '23000') {
                return response()->json(['message' => 'This action could not be completed because it conflicts with existing data.', 'reference' => $reference], 409);
            }

            return response()->json(['message' => 'A database error occurred. Please try again or contact support.', 'reference' => $reference], 500);
        }

        $debug = config('app.debug');

        return response()->json([
            'message' => $debug ? $e->getMessage() : 'Server error.',
            'exception' => $debug ? get_class($e) : null,
            'file' => $debug ? $e->getFile().':'.$e->getLine() : null,
            'reference' => $reference,
        ], 500);
    }
}
