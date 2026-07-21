<?php

namespace App\Providers;

use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Repositories\Contracts\AttachmentRepositoryInterface;
use App\Repositories\Contracts\DomainRepositoryInterface;
use App\Repositories\Contracts\EmailRepositoryInterface;
use App\Repositories\Contracts\InboxRepositoryInterface;
use App\Repositories\Contracts\MailServerRepositoryInterface;
use App\Repositories\Contracts\PlanRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Repositories\Eloquent\EloquentApiKeyRepository;
use App\Repositories\Eloquent\EloquentAttachmentRepository;
use App\Repositories\Eloquent\EloquentDomainRepository;
use App\Repositories\Eloquent\EloquentEmailRepository;
use App\Repositories\Eloquent\EloquentInboxRepository;
use App\Repositories\Eloquent\EloquentMailServerRepository;
use App\Repositories\Eloquent\EloquentPlanRepository;
use App\Repositories\Eloquent\EloquentSubscriptionRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
    }
}
