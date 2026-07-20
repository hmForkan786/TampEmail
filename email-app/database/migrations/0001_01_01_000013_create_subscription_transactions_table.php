<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Payment transactions and gateway responses for subscription billing.
     */
    public function up(): void
    {
        Schema::create('subscription_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('subscription_id')
                ->constrained('subscriptions')
                ->cascadeOnDelete();

            // Payment provider identifier (e.g. stripe, paddle); validated via PHP enum.
            $table->string('gateway', 50);

            $table->string('gateway_transaction_id', 255)->unique();

            $table->string('invoice_number', 100)->nullable();

            // Payment lifecycle state (pending, paid, refunded, etc.); validated via PHP enum.
            $table->string('payment_status', 30);

            $table->decimal('amount', 10, 2);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);

            $table->char('currency', 3);

            $table->string('payment_method', 50)->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->json('gateway_response')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('gateway');
            $table->index('payment_status');
            $table->index('paid_at');
            $table->index(['subscription_id', 'payment_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_transactions');
    }
};
