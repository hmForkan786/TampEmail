<?php

use App\Http\Middleware\ApplySecurityHeaders;
use App\Http\Middleware\ApiRequestLogger;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\RequireApiKeyScope;
use App\Http\Middleware\ThrottleApiKey;
use App\Http\Responses\ApiErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use App\Contracts\InboundWebhookDispatcher;
use App\Services\Inbound\QueuedInboundWebhookDispatcher;
use App\Contracts\AttachmentScannerInterface;
use App\Services\Inbound\DisabledAttachmentScanner;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBindings([
        InboundWebhookDispatcher::class => fn (): QueuedInboundWebhookDispatcher => new QueuedInboundWebhookDispatcher(),
        AttachmentScannerInterface::class => fn (): DisabledAttachmentScanner => new DisabledAttachmentScanner(),
    ])
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->call(function (): void {
            app(\App\Services\Ops\ProcessHeartbeatWriter::class)->schedulerTick();
        })->name('processes:scheduler-heartbeat')->withoutOverlapping()->everyMinute();
        $command = 'logs:cleanup --confirm';
        if (config('retention.audit_log_retention_cleanup_enabled', false) === true) {
            $command .= ' --confirm-audit-delete';
        }

        $event = $schedule->command($command)->withoutOverlapping();
        if (config('retention.cleanup_schedule') === 'hourly') {
            $event->hourly();
        } else {
            $event->daily();
        }
        if (config('inbound_retention.cleanup_enabled', false) === true) {
            $schedule->command('inbound:cleanup --confirm')->withoutOverlapping()->daily();
        }
        if (config('inbox_lifetime.expiration_scheduler_enabled', false) === true) {
            $schedule->command('inboxes:expire --confirm')->withoutOverlapping()->daily();
        }
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(ApplySecurityHeaders::class);
        $middleware->alias([
            'api.key' => AuthenticateApiKey::class,
            'api.request-log' => ApiRequestLogger::class,
            'api.scope' => RequireApiKeyScope::class,
            'api.rate-limit' => ThrottleApiKey::class,
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
