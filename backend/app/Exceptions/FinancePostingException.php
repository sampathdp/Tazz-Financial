<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A finance money-movement could not be executed for a business reason
 * (insufficient funds, a mis-shaped request, an already-reversed source).
 * Thrown by App\Support\FinancePostingService. Renders as a clean 422 so the
 * finance officer sees exactly why, without a stack trace.
 */
class FinancePostingException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
