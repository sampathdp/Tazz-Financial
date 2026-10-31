<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Every DB::transaction() retries on a deadlock (see the class docblock).
        \Illuminate\Database\Connection::resolverFor('mysql', function ($connection, $database, $prefix, $config) {
            return new \App\Support\DeadlockRetryingMySqlConnection($connection, $database, $prefix, $config);
        });
    }

    public function boot()
    {
        // MySQL safe default for older versions
        Schema::defaultStringLength(191);

        // Catch lazy loading in non-prod (Laravel 8.43+)
        if (method_exists(Model::class, 'preventLazyLoading')) {
            Model::preventLazyLoading(!app()->isProduction());
        }

        // Log slow queries to the performance channel
        $slowMs = (int) env('DB_SLOW_QUERY_MS', 500);
        DB::listen(function ($query) use ($slowMs) {
            if ($query->time >= $slowMs) {
                Log::channel('performance')->warning('slow_query', [
                    'sql' => $query->sql,
                    'time_ms' => $query->time,
                    'connection' => $query->connectionName,
                ]);
            }
        });
    }
}
