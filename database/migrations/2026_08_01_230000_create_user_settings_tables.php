<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 665 — typed user settings preferences and privacy export foundation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notification_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 64);
            $table->string('channel', 32);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'category', 'channel'], 'user_notification_prefs_unique');
            $table->index(['user_id', 'category']);
        });

        Schema::create('user_billing_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('billing_email')->nullable();
            $table->string('invoice_name')->nullable();
            $table->text('invoice_address')->nullable();
            $table->string('invoice_locale', 16)->nullable();
            $table->text('tax_identifier_encrypted')->nullable();
            $table->timestamps();
        });

        Schema::create('user_privacy_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 32)->default('pending')->index();
            $table->string('disk', 64)->nullable();
            $table->string('path', 512)->nullable();
            $table->json('included_datasets')->nullable();
            $table->json('deferred_datasets')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        if (Schema::hasTable('identity_preferences')) {
            Schema::table('identity_preferences', function (Blueprint $table): void {
                if (! Schema::hasColumn('identity_preferences', 'marketing_consent_source')) {
                    $table->string('marketing_consent_source', 64)->nullable()->after('marketing_consent_at');
                }
                if (! Schema::hasColumn('identity_preferences', 'marketing_policy_version')) {
                    $table->string('marketing_policy_version', 64)->nullable()->after('marketing_consent_source');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('identity_preferences')) {
            Schema::table('identity_preferences', function (Blueprint $table): void {
                $columns = ['marketing_consent_source', 'marketing_policy_version'];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('identity_preferences', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('user_privacy_exports');
        Schema::dropIfExists('user_billing_preferences');
        Schema::dropIfExists('user_notification_preferences');
    }
};
