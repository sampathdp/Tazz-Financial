<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
| NOTE: production deploys Laravel's public/ dir under /api on the FTP
| host (see .github/workflows/deploy.yml), with SCRIPT_NAME faked so the
| app believes it's mounted at the site root. Anything registered here in
| web.php (no /api prefix) is therefore only reachable at /<path> — a path
| that physically doesn't exist on the server, so the frontend SPA's
| catch-all .htaccess swallows it and serves index.html instead. Routes
| that must work in production (like the public share links, moved to
| routes/api.php) need the /api prefix that api.php routes get automatically.
*/

Route::get('/', function () {
    return view('welcome');
});

