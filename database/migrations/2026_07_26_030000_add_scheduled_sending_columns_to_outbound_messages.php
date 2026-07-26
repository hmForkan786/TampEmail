<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->timestamp('scheduled_at')->nullable()->after('draft_deleted_at');
            $table->string('scheduled_timezone', 64)->nullable()->after('scheduled_at');
            $table->foreignUuid('scheduled_by_user_id')->nullable()->after('scheduled_timezone')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('schedule_version')->default(0)->after('scheduled_by_user_id');
            $table->timestamp('scheduled_claimed_at')->nullable()->after('schedule_version');
            $table->string('schedule_defer_reason', 64)->nullable()->after('scheduled_claimed_at');
            $table->timestamp('schedule_next_attempt_at')->nullable()->after('schedule_defer_reason');
            $table->index(['state', 'scheduled_at'], 'outbound_scheduled_due_index');
            $table->index(['user_id', 'state'], 'outbound_owner_state_index');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->dropIndex('outbound_scheduled_due_index');
            $table->dropIndex('outbound_owner_state_index');
            $table->dropConstrainedForeignId('scheduled_by_user_id');
            $table->dropColumn([
                'scheduled_at',
                'scheduled_timezone',
                'schedule_version',
                'scheduled_claimed_at',
                'schedule_defer_reason',
                'schedule_next_attempt_at',
            ]);
        });
    }
};
