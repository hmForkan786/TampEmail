<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_placements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 80)->unique();
            $table->string('name', 160);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'display_order']);
        });

        Schema::create('ad_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->string('provider', 40);
            $table->string('purpose', 40)->default('monetization');
            $table->string('promotion_kind', 40)->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('priority')->default(100);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('daily_budget')->nullable();
            $table->unsignedInteger('max_impressions')->nullable();
            $table->unsignedInteger('max_clicks')->nullable();
            $table->unsignedInteger('impressions_today')->default(0);
            $table->unsignedInteger('impressions_total')->default(0);
            $table->unsignedInteger('clicks_today')->default(0);
            $table->unsignedInteger('clicks_total')->default(0);
            $table->date('budget_day')->nullable();
            $table->json('targeting')->nullable();
            $table->json('provider_config')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'priority']);
            $table->index(['provider', 'status']);
            $table->index(['purpose', 'status']);
            $table->index('starts_at');
            $table->index('ends_at');
        });

        Schema::create('ad_campaign_placement', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ad_campaign_id');
            $table->uuid('ad_placement_id');
            $table->timestamps();

            $table->unique(['ad_campaign_id', 'ad_placement_id']);
            $table->foreign('ad_campaign_id')->references('id')->on('ad_campaigns')->cascadeOnDelete();
            $table->foreign('ad_placement_id')->references('id')->on('ad_placements')->cascadeOnDelete();
        });

        Schema::create('ad_impressions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ad_campaign_id')->index();
            $table->uuid('ad_placement_id')->index();
            $table->uuid('user_id')->nullable()->index();
            $table->string('session_hash', 64)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('device', 20)->nullable();
            $table->string('language', 16)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();

            $table->foreign('ad_campaign_id')->references('id')->on('ad_campaigns')->cascadeOnDelete();
            $table->foreign('ad_placement_id')->references('id')->on('ad_placements')->cascadeOnDelete();
            $table->index('created_at');
        });

        Schema::create('ad_clicks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ad_campaign_id')->index();
            $table->uuid('ad_placement_id')->index();
            $table->uuid('ad_impression_id')->nullable()->index();
            $table->uuid('user_id')->nullable()->index();
            $table->string('session_hash', 64)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('device', 20)->nullable();
            $table->string('language', 16)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('destination_url', 2048)->nullable();
            $table->timestamps();

            $table->foreign('ad_campaign_id')->references('id')->on('ad_campaigns')->cascadeOnDelete();
            $table->foreign('ad_placement_id')->references('id')->on('ad_placements')->cascadeOnDelete();
            $table->foreign('ad_impression_id')->references('id')->on('ad_impressions')->nullOnDelete();
            $table->index('created_at');
        });

        Schema::create('ad_revenue_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('ad_campaign_id')->nullable()->index();
            $table->string('provider', 40)->nullable();
            $table->date('earned_on');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('USD');
            $table->string('source', 80)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->timestamps();

            $table->foreign('ad_campaign_id')->references('id')->on('ad_campaigns')->nullOnDelete();
            $table->index(['earned_on', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_revenue_entries');
        Schema::dropIfExists('ad_clicks');
        Schema::dropIfExists('ad_impressions');
        Schema::dropIfExists('ad_campaign_placement');
        Schema::dropIfExists('ad_campaigns');
        Schema::dropIfExists('ad_placements');
    }
};
