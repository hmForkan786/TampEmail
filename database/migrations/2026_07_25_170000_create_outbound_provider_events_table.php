<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
        });

        Schema::create('outbound_provider_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 64);
            $table->string('provider_event_id', 191);
            $table->string('provider_message_id', 255)->nullable()->index();
            $table->foreignUuid('outbound_message_id')->nullable()->constrained('outbound_messages')->nullOnDelete();
            $table->string('event_type', 64);
            $table->string('normalized_status', 64);
            $table->timestamp('received_at');
            $table->timestamp('provider_event_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('signature_state', 32)->default('verified');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['provider', 'provider_event_id'], 'outbound_provider_events_provider_event_unique');
            $table->index(['normalized_status', 'received_at']);
            $table->index(['outbound_message_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_provider_events');

        Schema::table('outbound_messages', function (Blueprint $table): void {
            $table->dropColumn('delivered_at');
        });
    }
};
