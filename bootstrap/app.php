<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Console\Scheduling\Schedule; // ← Add this

return Application::configure(basePath: dirname(__DIR__))

    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )

    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR |
            Request::HEADER_X_FORWARDED_HOST |
            Request::HEADER_X_FORWARDED_PORT |
            Request::HEADER_X_FORWARDED_PROTO
        );
        $middleware->api(append: [
            \App\Http\Middleware\PerformanceMonitoringMiddleware::class,
        ]);
        $middleware->append(\App\Http\Middleware\BlockSuspiciousIps::class);

        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'employee' => \App\Http\Middleware\EmployeeMiddleware::class,
        ]);

        $middleware->api(prepend: [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        $middleware->throttleApi();
    })

    /*
    |--------------------------------------------------------------------------
    | 📌 Add your scheduler here
    |--------------------------------------------------------------------------
    */
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('backup:database')->dailyAt('02:00');
    })

    ->withExceptions(function (Exceptions $exceptions) {
        //
    })

    ->create();
