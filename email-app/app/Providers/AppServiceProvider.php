<?php

namespace App\Providers;

use App\Repositories\Contracts\InboxRepositoryInterface;
use App\Repositories\Eloquent\EloquentInboxRepository;
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
            InboxRepositoryInterface::class,
            EloquentInboxRepository::class,
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
