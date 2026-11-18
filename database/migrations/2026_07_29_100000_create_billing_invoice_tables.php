<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_invoice_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::create('billing_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('invoice_number', 40)->nullable()->unique();
            $table->foreignUuid('billing_order_id')->constrained('billing_orders')->restrictOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->string('status', 20);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('provider_reference', 255)->nullable();
            $table->string('content_fingerprint', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('billing_order_id');
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'issued_at']);
            $table->index('provider');
            $table->index('subscription_id');
        });

        Schema::create('billing_invoice_line_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('billing_invoice_id')->constrained('billing_invoices')->restrictOnDelete();
            $table->string('description', 500);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->unsignedInteger('position')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('billing_invoice_id');
        });

        Schema::create('billing_credit_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('credit_note_number', 40)->nullable()->unique();
            $table->foreignUuid('billing_invoice_id')->constrained('billing_invoices')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor');
            $table->string('status', 20);
            $table->string('reason', 500)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['billing_invoice_id', 'status']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_credit_notes');
        Schema::dropIfExists('billing_invoice_line_items');
        Schema::dropIfExists('billing_invoices');
        Schema::dropIfExists('billing_invoice_sequences');
    }
};
