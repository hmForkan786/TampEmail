<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Metered feature usage counters per subscription billing period.
     */
    public function up(): void
    {
        Schema::create('subscription_usage', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('subscription_id')
                ->constrained('subscriptions')
                ->cascadeOnDelete();

            $table->foreignUuid('feature_id')
                ->constrained('features')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('used_value')->default(0);
            $table->unsignedBigInteger('limit_value')->nullable();

            // Usage reset interval (daily, monthly, etc.); validated via PHP enum.
            $table->string('reset_period', 20);

            $table->dateTime('period_start');
            $table->dateTime('period_end');

            $table->timestamp('last_used_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('reset_period');
            $table->index('period_end');
            $table->index(['subscription_id', 'feature_id']);
            $table->index(['subscription_id', 'period_end']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_usage');
    }
};
