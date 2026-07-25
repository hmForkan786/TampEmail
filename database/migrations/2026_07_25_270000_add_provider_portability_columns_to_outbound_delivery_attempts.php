<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 619: provider portability foundation.
 *
 * `provider` records which vendor identity was actually selected for this
 * specific attempt at the time it started — captured once, never re-derived
 * from live config later, so historical attempts stay attributable even
 * after `OUTBOUND_PRIMARY_PROVIDER` / `OUTBOUND_SECONDARY_PROVIDER` change.
 *
 * `failover_reason_code` is a safe, sanitized code recording why a
 * cross-provider failover was (or was not) permitted for this attempt. Never
 * populated with raw provider responses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_delivery_attempts', function (Blueprint $table): void {
            $table->string('provider', 32)->nullable()->after('outbound_message_id');
            $table->string('failover_reason_code', 64)->nullable()->after('provider_message_id');
            $table->index('provider');
        });

        // Backfill existing rows from the parent message's provider (or
        // 'unknown' when that is also unset) so historical attempts remain
        // attributable without ever re-inferring from current config.
        DB::table('outbound_delivery_attempts')
            ->whereNull('provider')
            ->update([
                'provider' => DB::raw(
                    "(SELECT COALESCE(m.provider, 'unknown') FROM outbound_messages m WHERE m.id = outbound_delivery_attempts.outbound_message_id)"
                ),
            ]);
    }

    public function down(): void
    {
        Schema::table('outbound_delivery_attempts', function (Blueprint $table): void {
            $table->dropIndex(['outbound_delivery_attempts_provider_index']);
            $table->dropColumn(['provider', 'failover_reason_code']);
        });
    }
};
