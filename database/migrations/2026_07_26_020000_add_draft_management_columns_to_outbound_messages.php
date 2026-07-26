<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->unsignedInteger('draft_version')->default(1)->after('state');
            $table->timestamp('draft_submitted_at')->nullable()->after('cancelled_at');
            $table->timestamp('draft_deleted_at')->nullable()->after('draft_submitted_at');
            $table->index(['user_id', 'state', 'draft_deleted_at', 'updated_at'], 'outbound_drafts_owner_index');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->dropIndex('outbound_drafts_owner_index');
            $table->dropColumn(['draft_version', 'draft_submitted_at', 'draft_deleted_at']);
        });
    }
};
