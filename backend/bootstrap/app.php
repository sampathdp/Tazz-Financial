<?php

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| The first thing we will do is create a new Laravel application instance
| which serves as the "glue" for all the components of Laravel, and is
| the IoC container for the system binding all of the various parts.
|
*/

$basePath = $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__);

$app = new Illuminate\Foundation\Application($basePath);

/*
|--------------------------------------------------------------------------
| Load Env From Outside The Webroot In Production
|--------------------------------------------------------------------------
|
| On the live server the real .env lives outside any web-accessible
| directory, in a sibling "envs" folder next to this app folder, as
| sk-enterpricess.env. Locally (and if that file isn't present) Laravel
| falls back to its normal .env in the app root.
|
*/

$prodEnvDir = dirname($basePath) . '/envs';
if (is_file($prodEnvDir . '/sk-enterpricess.env')) {
    $app->useEnvironmentPath($prodEnvDir);
    $app->loadEnvironmentFrom('sk-enterpricess.env');
}

/*
|--------------------------------------------------------------------------
| Bind Important Interfaces
|--------------------------------------------------------------------------
|
| Next, we need to bind some important interfaces into the container so
| we will be able to resolve them when needed. The kernels serve the
| incoming requests to this application from both the web and CLI.
|
*/

$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    App\Http\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

/*
|--------------------------------------------------------------------------
| Return The Application
|--------------------------------------------------------------------------
|
| This script returns the application instance. The instance is given to
| the calling script so we can separate the building of the instances
| from the actual running of the application and sending responses.
|
*/

return $app;
