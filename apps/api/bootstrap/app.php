<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureSuperuser;
use App\Http\Middleware\ResolveCurrentOrganisation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->api(prepend: [EnsureFrontendRequestsAreStateful::class]);

        $middleware->alias([
            'org.current' => ResolveCurrentOrganisation::class,
            'superuser' => EnsureSuperuser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
