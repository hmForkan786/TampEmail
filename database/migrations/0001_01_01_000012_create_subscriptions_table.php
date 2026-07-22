<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * User subscription lifecycle linked to a billing plan.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignUuid('plan_id')
                ->constrained('plans')
                ->restrictOnDelete();

            // Lifecycle state (trial, active, cancelled, etc.); validated via PHP enum.
            $table->string('status', 30);

            // Billing interval (monthly, yearly, lifetime); validated via PHP enum.
            $table->string('billing_cycle', 20);

            $table->timestamp('starts_at');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->boolean('auto_renew')->default(true);

            $table->decimal('price', 10, 2);
            $table->char('currency', 3);

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('ends_at');
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
