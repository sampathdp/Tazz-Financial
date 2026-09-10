<?php
// Reads OPS_TOKEN straight out of the deployed .env (no Laravel bootstrap yet —
// this file must keep working even when the app itself is broken), so each
// environment (prod, demo, ...) can carry its own secret instead of sharing
// one literal committed to source control.
//
// The candidate list must match bootstrap/app.php: on the live server the real
// env lives outside the webroot at <account home>/envs/tazz-investment.env, and
// this file sits at .../sites/<site>/api — the same three-levels-up walk the
// bootstrap does from .../sites/<site>/laravel lands on the same folder. The
// app-root .env paths are the local / fallback case.
function ops_token(): ?string
{
    $candidates = [
        dirname(__DIR__, 3) . '/envs/tazz-investment.env',
        __DIR__ . '/../laravel/.env',
        __DIR__ . '/../.env',
    ];
    foreach ($candidates as $envPath) {
        if (!is_file($envPath)) {
            continue;
        }
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^OPS_TOKEN=(.*)$/', trim($line), $m)) {
                return trim($m[1], " \t\n\r\0\x0B\"'");
            }
        }
    }
    return null;
}

$configured = ops_token();
// Token must come from a header, never the query string — GET params end up
// in access logs, proxy logs, and browser history, which would leak it.
$token = $_SERVER['HTTP_X_OPS_TOKEN'] ?? '';
if ($configured === null || $configured === '' || $token === '' || !hash_equals($configured, $token)) {
    http_response_code(403);
    die('Forbidden');
}

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$result = [];

// Action: log — show last 100 lines of Laravel log
if (($_GET['action'] ?? '') === 'log') {
    $logDir = __DIR__ . '/../laravel/storage/logs';
    $files = is_dir($logDir) ? array_diff(scandir($logDir), ['.', '..', '.gitignore']) : [];
    $result['log_dir'] = $logDir;
    $result['log_files'] = array_values($files);
    if (!empty($files)) {
        usort($files, function ($a, $b) use ($logDir) {
            return filemtime($logDir.'/'.$b) <=> filemtime($logDir.'/'.$a);
        });
        $latest = $logDir . '/' . reset($files);
        $result['latest_log'] = basename($latest);
        $content = file_exists($latest) ? file_get_contents($latest) : '';
        $lines = explode("\n", $content);
        $result['last_100_lines'] = array_slice($lines, -100);
    }
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

// Action: fix-storage — recreate storage/bootstrap-cache directories that a
// mirror/rsync deploy can silently skip when every file inside them is
// excluded (e.g. storage/framework/views/* is excluded from sync so
// compiled Blade caches aren't re-uploaded each deploy — but if the
// directory itself never existed on the server, lftp never creates the
// empty shell either, and Laravel's view compiler then fails with
// "Please provide a valid cache path.").
if (($_GET['action'] ?? '') === 'fix-storage') {
    $base = __DIR__ . '/../laravel/storage';
    $dirs = [
        $base . '/framework/cache/data',
        $base . '/framework/sessions',
        $base . '/framework/testing',
        $base . '/framework/views',
        $base . '/logs',
        $base . '/app/public',
        __DIR__ . '/../laravel/bootstrap/cache',
    ];
    $created = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            $created[] = $dir . ' (' . (mkdir($dir, 0775, true) ? 'created' : 'FAILED') . ')';
        } else {
            $created[] = $dir . ' (already existed)';
        }
    }
    $result['fix_storage'] = $created;
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}

// 1. OPcache reset
$result['opcache_reset'] = function_exists('opcache_reset') ? opcache_reset() : false;
clearstatcache(true);

// 2. Clear Laravel bootstrap caches
$cacheDir = __DIR__ . '/../laravel/bootstrap/cache';
$cleared = [];
foreach (['routes-v7.php', 'routes.php', 'config.php', 'packages.php', 'services.php', 'events.php'] as $f) {
    $path = $cacheDir . '/' . $f;
    if (file_exists($path)) {
        $cleared[] = $f . ' (' . (unlink($path) ? 'deleted' : 'failed') . ')';
    }
}
$result['laravel_cache_cleared'] = $cleared;

// Action: routes — list all registered Laravel routes
if (($_GET['action'] ?? '') === 'routes') {
    try {
        require __DIR__ . '/../laravel/vendor/autoload.php';
        $app = require __DIR__ . '/../laravel/bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $request = Illuminate\Http\Request::create('/test', 'GET');
        $app->instance('request', $request);
        $kernel->bootstrap();
        $routes = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $routes[] = [
                'methods' => $route->methods(),
                'uri' => $route->uri(),
                'name' => $route->getName(),
                'action' => $route->getActionName(),
            ];
        }
        $result['routes_count'] = count($routes);
        $result['routes'] = $routes;
    } catch (\Throwable $e) {
        $result['error'] = $e->getMessage();
        $result['trace'] = explode("\n", $e->getTraceAsString());
    }
}

// Action: migrate-status — report which migrations are already applied and
// which are pending, WITHOUT running anything. Every deploy calls this before
// ?action=migrate so the log always shows the DB's real state first.
//
// Action: migrate — boot Laravel and run pending migrations. Add &pretend=1 to
// print the SQL that would run without executing it (a dry run).
// Seeding is deliberately NOT triggered here — DatabaseSeeder reseeds demo
// data and must only ever be run intentionally from a trusted shell, never
// from a web-reachable endpoint.
if (in_array(($_GET['action'] ?? ''), ['migrate', 'migrate-status'], true)) {
    try {
        require __DIR__ . '/../laravel/vendor/autoload.php';
        $app = require __DIR__ . '/../laravel/bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        // Always report status first — applied vs pending — for both actions.
        $statusOutput = new Symfony\Component\Console\Output\BufferedOutput();
        $kernel->call('migrate:status', [], $statusOutput);
        $result['migrate_status'] = $statusOutput->fetch();

        if (($_GET['action'] ?? '') === 'migrate') {
            $pretend = ($_GET['pretend'] ?? '') === '1';

            // Auto-baseline. This prod DB predates the deploy pipeline and had
            // schema changes applied by hand (see backend/database/*.sql), so
            // Laravel's `migrations` ledger sits behind the real schema and a
            // plain `migrate` tries to re-create tables that already exist.
            //
            // For each pending migration, look only at its up() body: if that
            // body does nothing but Schema::create() for tables that ALL
            // already exist, record it as run instead of executing it. A
            // migration whose up() also alters/drops/renames a table, runs a
            // raw statement, or changes a column is left to run normally — a
            // real failure there still fails the deploy.
            if (!$pretend) {
                $migrator = $app->make('migrator');
                $repo = $migrator->getRepository();
                $schema = $app->make('db')->connection()->getSchemaBuilder();
                $ranList = $repo->getRan();
                $files = $migrator->getMigrationFiles(database_path('migrations'));
                $batch = $repo->getNextBatchNumber();
                $baselined = [];
                foreach ($files as $name => $path) {
                    if (in_array($name, $ranList, true)) {
                        continue;
                    }
                    $src = (string) file_get_contents($path);
                    // Isolate the up() method body.
                    $up = $src;
                    if (preg_match('/function\s+up\s*\(/', $src, $u, PREG_OFFSET_CAPTURE)) {
                        $from = $u[0][1];
                        $to = strlen($src);
                        if (preg_match('/function\s+down\s*\(/', $src, $d, PREG_OFFSET_CAPTURE, $from)) {
                            $to = $d[0][1];
                        }
                        $up = substr($src, $from, $to - $from);
                    }
                    preg_match_all('/Schema::create\(\s*[\'"]([^\'"]+)[\'"]/', $up, $m);
                    $creates = $m[1];
                    $touchesOther = preg_match('/Schema::(table|drop|dropIfExists|dropColumns?|rename)\s*\(/', $up)
                        || preg_match('/DB::(statement|unprepared|raw)\s*\(/', $up)
                        || strpos($up, '->change()') !== false;
                    if ($creates && !$touchesOther) {
                        $allExist = true;
                        foreach ($creates as $t) {
                            if (!$schema->hasTable($t)) {
                                $allExist = false;
                                break;
                            }
                        }
                        if ($allExist) {
                            $repo->log($name, $batch);
                            $baselined[] = $name;
                        }
                    }
                }
                $result['auto_baselined'] = $baselined;
            }

            $migrateOutput = new Symfony\Component\Console\Output\BufferedOutput();
            $kernel->call('migrate', ['--force' => true, '--pretend' => $pretend], $migrateOutput);
            $result['migrate'] = $migrateOutput->fetch();
            $result['pretend'] = $pretend;
        }
    } catch (\Throwable $e) {
        $result['error'] = $e->getMessage();
        $result['trace'] = $e->getTraceAsString();
    }
}

$result['time'] = date('c');
header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);
