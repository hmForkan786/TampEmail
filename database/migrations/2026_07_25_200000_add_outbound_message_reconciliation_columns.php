<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            // Set immediately before the transport is invoked. Null means the
            // worker died before ever submitting to the transport, so a stale
            // `sending` row is safe to requeue; non-null means the outcome is
            // ambiguous and must never be blindly resent.
            $table->timestamp('transport_attempted_at')->nullable()->after('sending_at');

            $table->timestamp('reconciliation_flagged_at')->nullable()->after('cancelled_at');
            $table->string('reconciliation_note', 80)->nullable()->after('reconciliation_flagged_at');

            $table->index(['state', 'sending_at']);
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->dropIndex(['state', 'sending_at']);
            $table->dropColumn(['transport_attempted_at', 'reconciliation_flagged_at', 'reconciliation_note']);
        });
    }
};
