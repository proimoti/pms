<?php

use App\Http\Middleware\EnforceTwoFactor;
use App\Http\Middleware\EnsureAccessNotStopped;
use App\Http\Middleware\EnsureCompanyAddress;
use App\Http\Middleware\EnsureCompanyIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // При всяка заявка: потребителят е от фирмата на адреса (27.11.2026), фирмата е
        // активна, после задължителен втори фактор.
        $middleware->web(append: [
            EnsureCompanyAddress::class,
            EnsureCompanyIsActive::class,
            EnsureAccessNotStopped::class,
            EnforceTwoFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // IBAN не се пази в сесията при грешка във формата (въвежда се наново).
        $exceptions->dontFlash(['iban']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
