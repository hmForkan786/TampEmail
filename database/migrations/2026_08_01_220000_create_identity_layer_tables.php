<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity Layer (Prompt 664): invites, recovery, login history, preferences, staged email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'terms_accepted_at')) {
                $table->timestamp('terms_accepted_at')->nullable()->after('locale');
            }
            if (! Schema::hasColumn('users', 'marketing_consent_at')) {
                $table->timestamp('marketing_consent_at')->nullable()->after('terms_accepted_at');
            }
            if (! Schema::hasColumn('users', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('last_login_ip');
            }
            if (! Schema::hasColumn('users', 'closure_scheduled_for')) {
                $table->timestamp('closure_scheduled_for')->nullable()->after('closed_at');
            }
            if (! Schema::hasColumn('users', 'pending_email')) {
                $table->string('pending_email')->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'pending_email_verified_at')) {
                $table->timestamp('pending_email_verified_at')->nullable()->after('pending_email');
            }
        });

        Schema::create('registration_invites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email')->nullable()->index();
            $table->string('token_hash', 64)->unique();
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('expires_at')->nullable()->index();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('account_recovery_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('claimed_email_hash', 64)->index();
            $table->text('new_email_encrypted')->nullable();
            $table->string('status', 32)->default('submitted')->index();
            $table->string('reason_code', 64);
            $table->text('evidence_notes_encrypted')->nullable();
            $table->string('submitted_ip_hash', 64)->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignUuid('second_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('second_reviewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->json('review_history')->nullable();
            $table->timestamps();
        });

        Schema::create('login_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email_hash', 64)->index();
            $table->boolean('success')->default(false)->index();
            $table->string('failure_reason_code', 64)->nullable();
            $table->string('ip_hash', 64)->nullable()->index();
            $table->string('user_agent_hash', 64)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        Schema::create('identity_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('marketing_consent')->default(false);
            $table->timestamp('marketing_consent_at')->nullable();
            $table->boolean('terms_accepted')->default(false);
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_preferences');
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('account_recovery_requests');
        Schema::dropIfExists('registration_invites');

        Schema::table('users', function (Blueprint $table): void {
            $columns = [
                'terms_accepted_at',
                'marketing_consent_at',
                'closed_at',
                'closure_scheduled_for',
                'pending_email',
                'pending_email_verified_at',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
