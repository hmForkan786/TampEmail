<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only pipeline stage logs for inbound email processing.
     */
    public function up(): void
    {
        Schema::create('email_processing_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('email_id')
                ->constrained('emails')
                ->cascadeOnDelete();

            // Pipeline stage (received, parsed, stored, etc.); validated via PHP enum.
            $table->string('stage', 50);

            // Stage outcome (started, success, failed, skipped); validated via PHP enum.
            $table->string('status', 20);

            $table->string('worker', 100)->nullable();

            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error_message')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('stage');
            $table->index('status');
            $table->index('created_at');
            $table->index(['email_id', 'created_at']);
            $table->index(['stage', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_processing_logs');
    }
};
