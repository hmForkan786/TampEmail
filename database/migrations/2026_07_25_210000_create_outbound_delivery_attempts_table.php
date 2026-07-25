<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_delivery_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('outbound_message_id')->constrained('outbound_messages')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');

            // Safe, coarse metadata only — never body, full recipients, raw
            // SMTP response, credentials, or attachment content.
            $table->string('transport', 64)->nullable();
            $table->string('state', 32);
            $table->string('result', 32)->nullable();
            $table->string('failure_category', 40)->nullable();
            $table->string('provider_message_id', 255)->nullable();
            $table->boolean('ambiguous')->default(false);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->unique(['outbound_message_id', 'attempt_number'], 'outbound_delivery_attempts_message_attempt_unique');
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_delivery_attempts');
    }
};
