<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Atomic per-message outbound usage reservation, one row per outbound
     * message, tracking the reserve -> commit|release lifecycle used by
     * OutboundUsageService to charge subscription usage counters without
     * double-charging or races. Kept fully separate from
     * outbound_abuse_blocks / OutboundRateLimiter (abuse enforcement).
     */
    public function up(): void
    {
        Schema::create('outbound_usage_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('outbound_message_id')
                ->unique()
                ->constrained('outbound_messages')
                ->cascadeOnDelete();

            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->foreignUuid('subscription_id')
                ->nullable()
                ->constrained('subscriptions')
                ->nullOnDelete();

            // send | reply | forward; validated via PHP enum.
            $table->string('operation', 20);

            $table->string('idempotency_key', 128);

            // reserved | committed | released | expired; validated via PHP enum.
            $table->string('state', 20)->default('reserved');

            $table->unsignedInteger('message_units')->default(1);
            $table->unsignedInteger('recipient_units')->default(0);
            $table->unsignedBigInteger('attachment_bytes')->default(0);

            $table->timestamp('reserved_at');
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Fixed, stable release reason code (never free text).
            $table->string('release_reason', 64)->nullable();

            // Non-secret counters: usage_ids (SubscriptionUsage rows charged
            // at commit time), attempts, retries, permanent_failures.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['state', 'expires_at']);
            $table->index(['user_id', 'state']);
            $table->index(['subscription_id', 'state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbound_usage_reservations');
    }
};
