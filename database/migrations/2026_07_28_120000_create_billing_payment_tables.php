<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('plan_id')->constrained('plans')->restrictOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('type', 30);
            $table->string('status', 40);
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->string('provider', 50)->nullable();
            $table->string('provider_reference', 255)->nullable();
            $table->string('idempotency_key', 255);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->unique(['provider', 'provider_reference'], 'billing_orders_provider_reference_unique');
            $table->index('status');
            $table->index(['user_id', 'status']);
            $table->index('expires_at');
        });

        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('billing_order_id')->constrained('billing_orders')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('provider', 50);
            $table->string('type', 40);
            $table->string('status', 30);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('provider_transaction_id', 255);
            $table->string('provider_event_id', 255)->nullable();
            $table->string('idempotency_key', 255);
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('payload_fingerprint', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_transaction_id'], 'payment_transactions_provider_tx_unique');
            $table->unique(['billing_order_id', 'idempotency_key'], 'payment_transactions_order_idempotency_unique');
            $table->index('status');
            $table->index(['billing_order_id', 'type', 'status']);
        });

        Schema::create('payment_provider_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 50);
            $table->string('provider_event_id', 255);
            $table->string('event_type', 100);
            $table->string('payload_hash', 64);
            $table->string('status', 30);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->json('payload_redacted')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id'], 'payment_provider_events_provider_event_unique');
            $table->index('status');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_provider_events');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('billing_orders');
    }
};
