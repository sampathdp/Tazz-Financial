<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    | Read through config() (never env() directly in routes/providers) so these
    | keep working when `php artisan config:cache` is run in production.
    |
    | Note on brute force: the real protection lives in AuthController, which
    | locks an email+IP after 5 *failed* attempts and clears on success. The
    | HTTP-layer limits below only need to stop scripted floods, so they can be
    | generous enough not to lock out legitimate users sharing an office IP.
    */

    // POST /api/login and /api/rep/login — per IP, per minute.
    // Counts every attempt, successful ones included.
    'login' => (int) env('LOGIN_RATE_LIMIT', 30),

    // All authenticated /api/* traffic — per user (falls back to IP).
    // The SPA fires several requests per screen, so this must be well above
    // a human's realistic click rate.
    'api' => (int) env('API_RATE_LIMIT', 300),

    // Public branding/logo endpoints hit before sign-in — per IP.
    'branding' => (int) env('BRANDING_RATE_LIMIT', 120),

];
