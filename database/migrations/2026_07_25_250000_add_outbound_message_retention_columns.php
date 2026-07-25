<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            // User-initiated hide. Never rewrites transport state; excludes the
            // message from normal owner views only (admin/ops views are
            // unaffected). Hard delete of the row happens much later, only
            // once content is redacted and no dependent rows remain.
            $table->timestamp('user_deleted_at')->nullable()->after('cancelled_at');

            // Set once body/content retention has expired and the row has
            // been redacted in place (subject/body/display-name cleared,
            // recipients reduced to hashes, attachment_ids nulled).
            $table->timestamp('content_redacted_at')->nullable()->after('user_deleted_at');

            // Legal/security hold. Non-null and in the future blocks every
            // prune category for this message; never restores user
            // visibility on its own.
            $table->timestamp('retention_hold_until')->nullable()->after('content_redacted_at');
            $table->string('retention_hold_reason_code', 40)->nullable()->after('retention_hold_until');

            $table->index('user_deleted_at');
            $table->index('content_redacted_at');
            $table->index('retention_hold_until');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->dropIndex(['user_deleted_at']);
            $table->dropIndex(['content_redacted_at']);
            $table->dropIndex(['retention_hold_until']);
            $table->dropColumn([
                'user_deleted_at',
                'content_redacted_at',
                'retention_hold_until',
                'retention_hold_reason_code',
            ]);
        });
    }
};
