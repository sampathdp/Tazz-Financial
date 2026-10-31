<?php

/**
 * One concurrent API caller for tests/Feature/ConcurrencyTest.php.
 *
 *   php tests/Support/concurrency_worker.php <base64 json {method,uri,body,user_id,start_at,key}>
 *
 * Boots the application in its own PHP process (so it has its own database connection and its own
 * transaction), waits until `start_at` (a float unix time shared by all workers, so they hit the
 * server together), sends one request through the HTTP kernel as the given user, and prints
 * {"status":int,"body":string}.
 */
$spec = json_decode(base64_decode($argv[1]), true);

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$user = App\Models\User::findOrFail($spec['user_id']);
Laravel\Sanctum\Sanctum::actingAs($user, ['*'], 'sanctum');

$request = Illuminate\Http\Request::create(
    $spec['uri'],
    $spec['method'],
    [],
    [],
    [],
    ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $spec['key']],
    json_encode($spec['body'])
);

while (microtime(true) < $spec['start_at']) {
    usleep(500);
}
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent()]);
