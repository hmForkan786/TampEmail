<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->string('authorization_reference')->nullable()->after('provider_event_id');
            $table->foreignUuid('parent_transaction_id')->nullable()->after('authorization_reference')
                ->constrained('payment_transactions')->restrictOnDelete();
            $table->timestamp('occurred_at')->nullable()->after('processed_at');
            $table->string('provider_status', 40)->nullable()->after('occurred_at');
            $table->index(['billing_order_id', 'processed_at']);
        });
        Schema::table('payment_provider_events', function (Blueprint $table): void {
            $table->index(['status', 'received_at']);
            $table->index(['provider', 'event_type']);
            $table->index('processed_at');
        });
        Schema::create('payment_settlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_transaction_id')->constrained('payment_transactions')->restrictOnDelete();
            $table->foreignUuid('billing_order_id')->constrained('billing_orders')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('provider_settlement_id')->nullable();
            $table->string('status', 30);
            $table->unsignedBigInteger('gross_amount_minor');
            $table->unsignedBigInteger('fee_amount_minor')->nullable();
            $table->unsignedBigInteger('tax_amount_minor')->nullable();
            $table->unsignedBigInteger('net_amount_minor')->nullable();
            $table->char('currency', 3);
            $table->timestamp('expected_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_settlement_id'], 'payment_settlement_provider_reference_unique');
            $table->index(['status', 'expected_at']);
            $table->index(['status', 'settled_at']);
        });
        Schema::table('billing_orders', function (Blueprint $table): void {
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('billing_orders', fn (Blueprint $table) => $table->dropIndex(['status', 'updated_at']));
        Schema::dropIfExists('payment_settlements');
        Schema::table('payment_provider_events', function (Blueprint $table): void {
            $table->dropIndex(['status', 'received_at']);
            $table->dropIndex(['provider', 'event_type']);
            $table->dropIndex(['processed_at']);
        });
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropIndex(['billing_order_id', 'processed_at']);
            $table->dropConstrainedForeignId('parent_transaction_id');
            $table->dropColumn(['authorization_reference', 'occurred_at', 'provider_status']);
        });
    }
};
