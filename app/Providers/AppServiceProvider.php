<?php

namespace App\Providers;

use App\Contracts\DnsResolverInterface;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Repositories\Contracts\AttachmentRepositoryInterface;
use App\Repositories\Contracts\DomainRepositoryInterface;
use App\Repositories\Contracts\EmailRepositoryInterface;
use App\Repositories\Contracts\FeatureRepositoryInterface;
use App\Repositories\Contracts\InboxRepositoryInterface;
use App\Repositories\Contracts\MailServerRepositoryInterface;
use App\Repositories\Contracts\PlanRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Repositories\Eloquent\EloquentApiKeyRepository;
use App\Repositories\Eloquent\EloquentAttachmentRepository;
use App\Repositories\Eloquent\EloquentDomainRepository;
use App\Repositories\Eloquent\EloquentEmailRepository;
use App\Repositories\Eloquent\EloquentFeatureRepository;
use App\Repositories\Eloquent\EloquentInboxRepository;
use App\Repositories\Eloquent\EloquentMailServerRepository;
use App\Repositories\Eloquent\EloquentPlanRepository;
use App\Repositories\Eloquent\EloquentSubscriptionRepository;
use App\Services\Billing\Callback\JsonCallbackResponseFormatter;
use App\Services\Billing\Callback\ProviderCallbackResponseFormatterRegistry;
use App\Services\Billing\Callback\SslCommerzCallbackResponseFormatter;
use App\Services\Billing\Callback\StripeCallbackResponseFormatter;
use App\Services\Billing\Payload\FormUrlEncodedProviderPayloadParser;
use App\Services\Billing\Payload\JsonProviderPayloadParser;
use App\Services\Billing\Payload\ProviderPayloadParserRegistry;
use App\Services\Billing\Payload\StripeProviderPayloadParser;
use App\Services\Billing\PaymentGatewayRegistry;
use App\Services\Billing\SslCommerz\SslCommerzValidationClient;
use App\Services\Billing\Webhook\FakeProviderWebhookVerifier;
use App\Services\Billing\Webhook\ProviderWebhookVerifierRegistry;
use App\Services\Billing\Webhook\SslCommerzProviderWebhookVerifier;
use App\Services\Billing\Webhook\StripeProviderWebhookVerifier;
use App\Services\Billing\Webhook\UnconfiguredProviderWebhookVerifier;
use App\Services\Dns\PhpDnsResolver;
use App\Services\Ops\ProcessHeartbeatWriter;
use App\Services\Outbound\GenericOutboundProviderEventParser;
use App\Services\Outbound\OutboundProviderEventParserRegistry;
use App\Services\Outbound\OutboundProviderRegistry;
use App\Services\Outbound\OutboundTransportConfigValidator;
use App\Services\Outbound\OutboundTransportManager;
use App\Services\Outbound\SesOutboundProviderEventParser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProcessHeartbeatWriter::class);

        $this->app->singleton(DnsResolverInterface::class, PhpDnsResolver::class);

        $this->app->bind(
            ApiKeyRepositoryInterface::class,
            EloquentApiKeyRepository::class,
        );

        $this->app->bind(
            InboxRepositoryInterface::class,
            EloquentInboxRepository::class,
        );

        $this->app->bind(
            EmailRepositoryInterface::class,
            EloquentEmailRepository::class,
        );

        $this->app->bind(
            AttachmentRepositoryInterface::class,
            EloquentAttachmentRepository::class,
        );

        $this->app->bind(
            DomainRepositoryInterface::class,
            EloquentDomainRepository::class,
        );

        $this->app->bind(
            SubscriptionRepositoryInterface::class,
            EloquentSubscriptionRepository::class,
        );

        $this->app->bind(
            MailServerRepositoryInterface::class,
            EloquentMailServerRepository::class,
        );

        $this->app->bind(
            PlanRepositoryInterface::class,
            EloquentPlanRepository::class,
        );

        $this->app->bind(
            FeatureRepositoryInterface::class,
            EloquentFeatureRepository::class,
        );

        $this->app->singleton(OutboundProviderEventParserRegistry::class, function ($app): OutboundProviderEventParserRegistry {
            return new OutboundProviderEventParserRegistry([
                $app->make(GenericOutboundProviderEventParser::class),
                $app->make(SesOutboundProviderEventParser::class),
            ]);
        });

        // Prompt 619: single source of truth for provider identity, capability,
        // and readiness lookups. Depends only on already-bound/autowireable
        // services, so this binding exists mainly to document intent and give
        // a stable resolution point for tests/ops.
        $this->app->singleton(OutboundProviderRegistry::class, function ($app): OutboundProviderRegistry {
            return new OutboundProviderRegistry(
                $app->make(OutboundTransportManager::class),
                $app->make(OutboundProviderEventParserRegistry::class),
                $app->make(OutboundTransportConfigValidator::class),
            );
        });

        $this->app->singleton(PaymentGatewayRegistry::class);
        $this->app->singleton(SslCommerzValidationClient::class);
        $this->app->singleton(ProviderPayloadParserRegistry::class, fn ($app): ProviderPayloadParserRegistry => new ProviderPayloadParserRegistry([
            $app->make(FormUrlEncodedProviderPayloadParser::class),
            $app->make(StripeProviderPayloadParser::class),
            $app->make(JsonProviderPayloadParser::class),
        ]));
        $this->app->singleton(ProviderCallbackResponseFormatterRegistry::class, fn ($app): ProviderCallbackResponseFormatterRegistry => new ProviderCallbackResponseFormatterRegistry([
            $app->make(SslCommerzCallbackResponseFormatter::class),
            $app->make(StripeCallbackResponseFormatter::class),
            $app->make(JsonCallbackResponseFormatter::class),
        ]));
        $this->app->singleton(ProviderWebhookVerifierRegistry::class, fn ($app): ProviderWebhookVerifierRegistry => new ProviderWebhookVerifierRegistry([
            $app->make(FakeProviderWebhookVerifier::class),
            $app->make(StripeProviderWebhookVerifier::class),
            $app->make(SslCommerzProviderWebhookVerifier::class),
            new UnconfiguredProviderWebhookVerifier('bkash'),
            new UnconfiguredProviderWebhookVerifier('nagad'),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('web', function (Request $request): Limit {
            return Limit::perMinute(config('abuse.rate_limits.web_per_minute'))
                ->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(config('abuse.rate_limits.api_per_minute'))
                ->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('inbox-creation', function (Request $request): Limit {
            return Limit::perHour(config('abuse.rate_limits.inbox_creation_per_hour'))
                ->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('ingestion', function (Request $request): Limit {
            return Limit::perMinute(config('abuse.rate_limits.ingestion_per_minute'))
                ->by($request->ip());
        });

        RateLimiter::for('billing-checkout', function (Request $request): array {
            $owner = (string) ($request->user()?->getKey() ?: $request->ip());

            return [
                Limit::perMinute(10)->by('billing-user:'.$owner),
                Limit::perMinute(30)->by('billing-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('billing-return', fn (Request $request): Limit => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('billing-callback', function (Request $request): array {
            $provider = strtolower((string) $request->route('provider'));

            return [
                Limit::perMinute((int) config('billing.webhook_security.rate_limits.per_ip_per_minute', 120))->by('billing-webhook-ip:'.$request->ip()),
                Limit::perMinute((int) config('billing.webhook_security.rate_limits.per_provider_per_minute', 300))->by('billing-webhook-provider:'.$provider),
            ];
        });

        Queue::starting(function (WorkerStarting $event): void {
            app(ProcessHeartbeatWriter::class)->recordWorkerStarting($event->connectionName, (string) $event->queue);
        });

        Queue::looping(function (Looping $event): void {
            app(ProcessHeartbeatWriter::class)->recordWorkerLoop($event->connectionName, (string) $event->queue);
        });

        Queue::before(function (JobProcessing $event): void {
            app(ProcessHeartbeatWriter::class)->recordWorkerStarting($event->connectionName, (string) $event->job->getQueue());
        });

        Queue::after(function (JobProcessed $event): void {
            app(ProcessHeartbeatWriter::class)->recordWorkerProcessed((string) $event->job->getQueue());
        });

        Queue::failing(function (JobFailed $event): void {
            app(ProcessHeartbeatWriter::class)->recordWorkerFailed((string) $event->job->getQueue());
        });

        Queue::stopping(function (WorkerStopping $event): void {
            app(ProcessHeartbeatWriter::class)->recordWorkerStopping();
        });

        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event): void {
            app(ProcessHeartbeatWriter::class)->recordSchedulerStarting();
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            app(ProcessHeartbeatWriter::class)->recordSchedulerSucceeded();
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            app(ProcessHeartbeatWriter::class)->recordSchedulerFailed($event->exception);
        });
    }
}
