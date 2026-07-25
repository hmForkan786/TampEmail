<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_provider_events', function (Blueprint $table): void {
            // Normalized outcome of the most recent transition attempt
            // (e.g. `delivered`, `unmatched`, `ignored_state`). Lets bounded
            // reconciliation passes find events worth re-evaluating (e.g.
            // out-of-order `ignored_state`) without rescanning everything.
            $table->string('outcome', 40)->nullable()->after('metadata');

            $table->unsignedSmallInteger('reconciliation_attempts')->default(0)->after('outcome');

            // Set once an unmatched event ages out of the correlation
            // window without ever finding its message. Terminal unmatched
            // events are kept (never deleted) for admin visibility but are
            // no longer evaluated by reconciliation.
            $table->timestamp('terminal_unmatched_at')->nullable()->after('reconciliation_attempts');

            $table->index(['outcome', 'received_at']);
            $table->index('terminal_unmatched_at');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_provider_events', function (Blueprint $table): void {
            $table->dropIndex(['outcome', 'received_at']);
            $table->dropIndex(['terminal_unmatched_at']);
            $table->dropColumn(['outcome', 'reconciliation_attempts', 'terminal_unmatched_at']);
        });
    }
};
