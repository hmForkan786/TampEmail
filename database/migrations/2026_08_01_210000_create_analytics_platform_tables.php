<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('domain', 40);
            $table->string('metric_key', 80);
            $table->decimal('value', 20, 4)->default(1);
            $table->timestamp('occurred_at');
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_event', 120)->nullable();
            $table->json('dimensions')->nullable();
            $table->timestamps();

            $table->index(['domain', 'metric_key', 'occurred_at'], 'analytics_events_domain_metric_occurred_idx');
            $table->index(['owner_id', 'occurred_at']);
            $table->index('occurred_at');
        });

        Schema::create('analytics_daily_rollups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('bucket_date');
            $table->string('domain', 40);
            $table->string('metric_key', 80);
            $table->decimal('value', 20, 4)->default(0);
            // 'platform' or owner UUID — avoids UNIQUE+NULL pitfalls across engines.
            $table->string('scope_key', 64)->default('platform');
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['bucket_date', 'domain', 'metric_key', 'scope_key'],
                'analytics_daily_rollups_unique_bucket'
            );
            $table->index(['domain', 'metric_key', 'bucket_date'], 'analytics_daily_rollups_domain_metric_date_idx');
            $table->index(['owner_id', 'bucket_date']);
            $table->index('scope_key');
        });

        Schema::create('analytics_aggregation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('bucket_date');
            $table->string('status', 20);
            $table->unsignedInteger('metrics_written')->default(0);
            $table->unsignedInteger('events_ingested')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['bucket_date', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_aggregation_runs');
        Schema::dropIfExists('analytics_daily_rollups');
        Schema::dropIfExists('analytics_events');
    }
};
