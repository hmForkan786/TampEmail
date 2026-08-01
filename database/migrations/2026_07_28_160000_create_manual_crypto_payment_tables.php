<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_crypto_checkout_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('billing_order_id')->unique()->constrained('billing_orders')->restrictOnDelete();
            $table->string('wallet_id', 100);
            $table->text('wallet_address');
            $table->string('asset', 16);
            $table->string('network', 32);
            $table->unsignedBigInteger('expected_amount_minor');
            $table->string('currency', 8);
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('manual_crypto_payment_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('billing_order_id')->unique()->constrained('billing_orders')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('checkout_snapshot_id')->constrained('manual_crypto_checkout_snapshots')->restrictOnDelete();
            $table->string('network', 32);
            $table->string('txid', 64);
            $table->unsignedBigInteger('submitted_amount_units');
            $table->text('screenshot_path')->nullable();
            $table->string('state', 32);
            $table->string('evidence_status', 32);
            $table->foreignUuid('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_reason')->nullable();
            $table->string('provider_event_id', 120)->nullable()->unique();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->unique(['network', 'txid']);
            $table->index(['state', 'submitted_at']);
        });

        Schema::create('manual_crypto_review_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('claim_id')->constrained('manual_crypto_payment_claims')->restrictOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 48);
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['claim_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_crypto_review_events');
        Schema::dropIfExists('manual_crypto_payment_claims');
        Schema::dropIfExists('manual_crypto_checkout_snapshots');
    }
};
