<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only lifecycle events for inbound email processing and access.
     */
    public function up(): void
    {
        Schema::create('email_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('email_id')
                ->constrained('emails')
                ->cascadeOnDelete();

            // Event classification (e.g. received, parsed, deleted); validated via PHP enum.
            $table->string('event_type', 50);

            // Originating channel (e.g. smtp, worker, api, user); validated via PHP enum.
            $table->string('event_source', 50)->nullable();

            $table->foreignUuid('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->json('payload')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index('event_type');
            $table->index('occurred_at');
            $table->index(['email_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_events');
    }
};
