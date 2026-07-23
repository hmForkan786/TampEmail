<?php

namespace App\Providers;

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
use App\Services\Ops\ProcessHeartbeatWriter;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Cache\RateLimiting\Limit;
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
