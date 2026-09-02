<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Request-level idempotency (F-4). A client that retries a create request
 * (payment, deposit, bank transaction, ...) after a timeout sends the same
 * Idempotency-Key header; the Idempotency middleware records the first
 * response against (key, method, path) and replays it for any retry instead
 * of letting the handler run — and post — a second time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->string('idempotency_key', 128);
            $t->string('method', 8);
            $t->string('path', 191);
            $t->string('request_fingerprint', 64)->nullable();
            $t->unsignedSmallInteger('response_status')->nullable();
            $t->mediumText('response_body')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();

            $t->unique(['idempotency_key', 'method', 'path'], 'uq_idempotency');
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
