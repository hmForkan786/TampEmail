<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Subscription plan definitions for entitlement-based product limits.
     * Foreign keys to subscriptions and plan_entitlements are added in later migrations.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            // UUID primary key per billing module requirements; slug remains the stable business identifier.
            $table->uuid('id')->primary();

            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();

            // Monetary values stored as DECIMAL to avoid floating-point rounding errors.
            $table->decimal('price_monthly', 10, 2);
            $table->decimal('price_yearly', 10, 2);
            $table->char('currency', 3)->default('USD')->index();

            $table->boolean('is_free')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);

            // Optional plan-specific configuration (entitlement hints, feature flags, display metadata).
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'display_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
