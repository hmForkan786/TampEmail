<?php

use App\Http\Middleware\ApplySecurityHeaders;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\RequireApiKeyScope;
use App\Http\Responses\ApiErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(ApplySecurityHeaders::class);
        $middleware->alias([
            'api.key' => AuthenticateApiKey::class,
            'api.scope' => RequireApiKeyScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiErrorResponse::make(
                'not_found',
                'Resource not found.',
                404,
            );
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface || $e instanceof HttpResponseException) {
                return null;
            }

            if (config('app.debug')) {
                return null;
            }

            return ApiErrorResponse::make(
                'server_error',
                'An unexpected error occurred.',
                500,
            );
        });
    })->create();
