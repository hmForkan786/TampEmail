<?php

use App\Console\Commands\CreateRenewalOrdersCommand;
use App\Console\Commands\ExpireBillingCheckoutsCommand;
use App\Console\Commands\ExpireLifecycleSubscriptionsCommand;
use App\Console\Commands\ProcessRuntimeSmoke;
use App\Console\Commands\PruneBillingWebhookSecurityCommand;
use App\Console\Commands\SslCommerzHealthCommand;
use App\Console\Commands\StartGracePeriodsCommand;
use App\Console\Commands\StripeHealthCommand;
use App\Console\Commands\SyncBillingPaymentStatusCommand;
use App\Console\Commands\VerifyBillingWebhookCommand;
use App\Contracts\AttachmentScannerInterface;
use App\Contracts\InboundWebhookDispatcher;
use App\Contracts\OutboundTransportInterface;
use App\Http\Middleware\ApiRequestLogger;
use App\Http\Middleware\ApplySecurityHeaders;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\CaptureAffiliateReferral;
use App\Http\Middleware\EnsureActiveWebUser;
use App\Http\Middleware\EnsureCommercialApiEntitlement;
use App\Http\Middleware\EnsureVerifiedActiveUser;
use App\Http\Middleware\RequireApiKeyScope;
use App\Http\Middleware\ThrottleApiKey;
use App\Http\Responses\ApiErrorResponse;
use App\Services\Inbound\ClamAvAttachmentScanner;
use App\Services\Inbound\DisabledAttachmentScanner;
use App\Services\Inbound\QueuedInboundWebhookDispatcher;
use App\Services\Outbound\OutboundTransportManager;
use Illuminate\Console\Scheduling\Schedule;
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
    ->withCommands([
        ExpireBillingCheckoutsCommand::class,
        CreateRenewalOrdersCommand::class,
        StartGracePeriodsCommand::class,
        ExpireLifecycleSubscriptionsCommand::class,
        SyncBillingPaymentStatusCommand::class,
        PruneBillingWebhookSecurityCommand::class,
        VerifyBillingWebhookCommand::class,
        SslCommerzHealthCommand::class,
        StripeHealthCommand::class,
        ProcessRuntimeSmoke::class,
    ])
    ->withBindings([
        InboundWebhookDispatcher::class => fn (): QueuedInboundWebhookDispatcher => new QueuedInboundWebhookDispatcher,
        AttachmentScannerInterface::class => fn (): AttachmentScannerInterface => config('attachments.scanner_backend', 'disabled') === 'clamav'
            ? new ClamAvAttachmentScanner
            : new DisabledAttachmentScanner,
        OutboundTransportInterface::class => fn (): OutboundTransportInterface => app(OutboundTransportManager::class)->resolve(),
    ])
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('processes:scheduler-heartbeat')->withoutOverlapping()->everyMinute();
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
        if (config('outbound_retention.cleanup_enabled', false) === true) {
            $schedule->command('outbound:prune --confirm')->withoutOverlapping()->daily();
        }
        $schedule->command('outbound:verify-domains')->withoutOverlapping()->hourly();
        $schedule->command('outbound:reconcile-stale-sending')->withoutOverlapping()->everyFiveMinutes();
        $schedule->command('outbound:reconcile-unmatched-events')->withoutOverlapping()->everyFifteenMinutes();
        $schedule->command('outbound:reconcile-events')->withoutOverlapping()->everyFifteenMinutes();
        $schedule->command('outbound:reconcile-usage')->withoutOverlapping()->everyFifteenMinutes();
        $schedule->command('outbound:dispatch-scheduled')->everyMinute()->withoutOverlapping();
        $schedule->command('billing:create-renewal-orders')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('billing:start-grace-periods')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('billing:expire-lifecycle-subscriptions')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('subscriptions:expire --batch=100')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('billing:expire-checkouts')->everyFiveMinutes()->withoutOverlapping();
        if (config('billing.payment_sync.enabled', true)) {
            $schedule->command('billing:sync-payment-status')->everyFiveMinutes()->withoutOverlapping();
        }
        $schedule->command('billing:prune-webhook-security')->daily()->withoutOverlapping();
        $schedule->command('mail-servers:refresh-ha')->withoutOverlapping()->everyFiveMinutes();
        if (config('ads.scheduler.expire_campaigns', true) === true) {
            $schedule->command('ads:expire-campaigns')->hourly()->withoutOverlapping();
        }
        if (config('ads.scheduler.refresh_budgets', true) === true) {
            $schedule->command('ads:refresh-budgets')->daily()->withoutOverlapping();
        }
        if (config('ads.scheduler.prune_statistics', true) === true) {
            $schedule->command('ads:prune-statistics --confirm')->daily()->withoutOverlapping();
        }
        if (config('affiliates.scheduler.maturity_enabled') === true) {
            $schedule->command('affiliates:mature-commissions')->hourly()->withoutOverlapping();
        }
        if (config('affiliates.scheduler.attribution_expire_enabled') === true) {
            $schedule->command('affiliates:expire-attributions')->hourly()->withoutOverlapping();
        }
        if (config('affiliates.scheduler.attribution_prune_enabled') === true) {
            $schedule->command('affiliates:prune-attributions --confirm')->daily()->withoutOverlapping();
        }
        if (config('analytics.scheduler.rollup_enabled', true) === true) {
            $schedule->command('analytics:rollup --backfill')->dailyAt('01:15')->withoutOverlapping();
        }
        if (config('analytics.scheduler.prune_enabled', true) === true) {
            $schedule->command('analytics:prune --confirm')->dailyAt('02:15')->withoutOverlapping();
        }
        if (config('identity.scheduler.prune_login_history', true) === true) {
            $schedule->command('identity:prune-login-history')->dailyAt('03:10')->withoutOverlapping();
        }
        if (config('identity.scheduler.expire_invites', true) === true) {
            $schedule->command('identity:expire-invites')->hourly()->withoutOverlapping();
        }
        if (config('identity.scheduler.expire_recovery', true) === true) {
            $schedule->command('identity:expire-recovery-requests')->hourly()->withoutOverlapping();
        }
        if (config('identity.scheduler.prune_unverified', true) === true) {
            $schedule->command('identity:prune-unverified-users')->dailyAt('03:25')->withoutOverlapping();
        }
        if (config('settings.scheduler.prune_expired_exports', true) === true) {
            $schedule->command('settings:prune-expired-exports --confirm')->dailyAt('03:40')->withoutOverlapping();
        }
        if (config('settings.scheduler.expire_stale_email_changes', true) === true) {
            $schedule->command('settings:expire-stale-email-changes --confirm')->dailyAt('03:50')->withoutOverlapping();
        }
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(ApplySecurityHeaders::class);
        $middleware->alias([
            'api.key' => AuthenticateApiKey::class,
            'api.request-log' => ApiRequestLogger::class,
            'api.scope' => RequireApiKeyScope::class,
            'api.rate-limit' => ThrottleApiKey::class,
            'api.entitlement' => EnsureCommercialApiEntitlement::class,
            'web.active' => EnsureActiveWebUser::class,
            'identity.verified' => EnsureVerifiedActiveUser::class,
            'affiliate.capture' => CaptureAffiliateReferral::class,
        ]);
        $middleware->web(append: [
            CaptureAffiliateReferral::class,
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

        $exceptions->render(function (Throwable $e, Request $request) {
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
