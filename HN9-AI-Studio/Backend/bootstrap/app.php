<?php

use App\Exceptions\DomainException;
use App\Http\Middleware\ForceJsonResponse;
use App\Support\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Versioned API surface. Each version lives in its own route file
            // under routes/api and is mounted at /api/{version}. New versions
            // are additive and never mutate an existing contract.
            Route::middleware('api')
                ->prefix('api/v1')
                ->name('api.v1.')
                ->group(base_path('routes/api/v1.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Ensure every /api/* response is JSON, even for framework-thrown
        // exceptions (validation, auth, not-found), so API clients never
        // receive an HTML error page.
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render a consistent JSON envelope for API requests.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        // Every domain failure already carries its own machine-readable error
        // code, HTTP status hint and client-safe context. Without this renderer
        // they surface as unhandled 500s with a stack trace, which is both the
        // wrong status (a non-editable project is a 409, an unsupported
        // deliverable a 422) and a leak. AIException extends DomainException, so
        // provider failures are covered by the same single catch site.
        $exceptions->render(function (DomainException $exception, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return ApiResponse::error(
                message: $exception->getMessage(),
                errorCode: $exception->errorCode(),
                status: $exception->statusCode(),
                context: $exception->context(),
            );
        });
    })->create();
