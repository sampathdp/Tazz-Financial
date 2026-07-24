<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $module, string $action)
    {
        $user = $request->user();
        if (!Permissions::userCan($user, $module, $action)) {
            Log::channel('security')->warning('permission_denied', [
                'user_id' => $user?->id,
                'module' => $module,
                'action' => $action,
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => "You don't have permission to {$action} {$module}."], 403);
        }

        return $next($request);
    }
}
