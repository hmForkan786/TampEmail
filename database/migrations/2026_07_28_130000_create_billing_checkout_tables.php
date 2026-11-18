<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_checkout_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->foreignUuid('billing_order_id')->nullable()->constrained('billing_orders')->restrictOnDelete();
            $table->string('gateway', 32);
            $table->string('status', 32);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key'], 'billing_checkout_request_user_key_unique');
            $table->index('request_fingerprint');
        });

        Schema::create('billing_checkout_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('billing_order_id')->constrained('billing_orders')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('status', 32);
            $table->string('provider_session_id')->nullable();
            $table->string('provider_reference')->nullable();
            $table->text('checkout_url')->nullable();
            $table->char('request_fingerprint', 64);
            $table->timestamp('expires_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->string('last_error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_session_id'], 'billing_checkout_provider_session_unique');
            $table->index(['billing_order_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index(['user_id', 'created_at']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('billing_checkout_sessions');
        Schema::dropIfExists('billing_checkout_requests');
    }
};
