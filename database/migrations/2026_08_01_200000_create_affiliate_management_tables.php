<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_commission_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('status', 20)->default('active');
            $table->string('commission_type', 20);
            $table->unsignedInteger('percentage_bps')->nullable();
            $table->unsignedBigInteger('fixed_amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('minimum_order_minor')->nullable();
            $table->unsignedBigInteger('maximum_commission_minor')->nullable();
            $table->unsignedInteger('cookie_window_days')->default(30);
            $table->unsignedInteger('commission_hold_days')->default(14);
            $table->boolean('new_customer_only')->default(true);
            $table->boolean('recurring_commission_enabled')->default(false);
            $table->unsignedInteger('recurring_cycles')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('affiliate_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('affiliate_code', 32)->unique();
            $table->string('status', 20)->default('pending');
            $table->foreignUuid('commission_plan_id')->nullable()
                ->constrained('affiliate_commission_plans')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('payout_method', 30)->nullable();
            $table->text('payout_details_encrypted')->nullable();
            $table->text('application_notes')->nullable();
            $table->string('promotion_channel', 100)->nullable();
            $table->string('website_url', 255)->nullable();
            $table->text('audience_description')->nullable();
            $table->string('expected_traffic', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('affiliate_attributions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('affiliate_profile_id')->constrained('affiliate_profiles')->restrictOnDelete();
            $table->string('visitor_token_hash', 64);
            $table->string('referral_code', 32);
            $table->string('landing_url', 2048)->nullable();
            $table->string('referrer_url', 2048)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('expires_at')->useCurrent();
            $table->foreignUuid('converted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('visitor_token_hash');
            $table->index(['affiliate_profile_id', 'status', 'expires_at'], 'aff_attr_profile_status_expires_idx');
        });

        Schema::create('affiliate_conversions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('affiliate_profile_id')->constrained('affiliate_profiles')->restrictOnDelete();
            $table->foreignUuid('attribution_id')->nullable()->constrained('affiliate_attributions')->nullOnDelete();
            $table->foreignUuid('referred_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('billing_order_id')->unique()->constrained('billing_orders')->restrictOnDelete();
            $table->uuid('subscription_id')->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('order_amount_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('commission_amount_minor');
            $table->json('commission_plan_snapshot');
            $table->timestamp('qualified_at')->useCurrent();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->timestamps();

            $table->index(['affiliate_profile_id', 'status', 'qualified_at'], 'aff_conv_profile_status_qualified_idx');
        });

        Schema::create('affiliate_commission_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('affiliate_profile_id')->constrained('affiliate_profiles')->restrictOnDelete();
            $table->foreignUuid('conversion_id')->nullable()->constrained('affiliate_conversions')->nullOnDelete();
            $table->uuid('withdrawal_id')->nullable()->index();
            $table->string('entry_type', 30);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->timestamp('available_at')->nullable();
            $table->string('reference_type', 100)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 120)->unique()->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['affiliate_profile_id', 'currency', 'status'], 'aff_ce_profile_currency_status_idx');
        });

        Schema::create('affiliate_withdrawals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('affiliate_profile_id')->constrained('affiliate_profiles')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 20)->default('requested');
            $table->string('payout_method', 30);
            $table->text('payout_details_snapshot_encrypted');
            $table->string('idempotency_key', 120);
            $table->timestamp('requested_at')->useCurrent();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('external_reference', 255)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->unique(['affiliate_profile_id', 'idempotency_key'], 'aff_wd_profile_idempotency_uq');
            $table->index(['affiliate_profile_id', 'status', 'created_at'], 'aff_wd_profile_status_created_idx');
        });

        Schema::table('affiliate_commission_entries', function (Blueprint $table): void {
            $table->foreign('withdrawal_id')->references('id')->on('affiliate_withdrawals')->restrictOnDelete();
        });

        Schema::create('affiliate_fraud_flags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('affiliate_profile_id')->nullable()->constrained('affiliate_profiles')->nullOnDelete();
            $table->uuid('conversion_id')->nullable();
            $table->uuid('attribution_id')->nullable();
            $table->uuid('referred_user_id')->nullable();
            $table->string('decision', 20);
            $table->json('reason_codes');
            $table->json('context')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['affiliate_profile_id', 'created_at'], 'aff_fraud_profile_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_fraud_flags');

        Schema::table('affiliate_commission_entries', function (Blueprint $table): void {
            $table->dropForeign(['withdrawal_id']);
        });

        Schema::dropIfExists('affiliate_withdrawals');
        Schema::dropIfExists('affiliate_commission_entries');
        Schema::dropIfExists('affiliate_conversions');
        Schema::dropIfExists('affiliate_attributions');
        Schema::dropIfExists('affiliate_profiles');
        Schema::dropIfExists('affiliate_commission_plans');
    }
};
