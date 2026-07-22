<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Inbound email metadata only; body content is stored separately.
     */
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('inbox_id')
                ->constrained('inboxes')
                ->cascadeOnDelete();

            $table->string('message_id', 255)->unique();

            $table->string('sender_name', 255)->nullable();
            $table->string('sender_email', 255);
            $table->string('recipient_email', 255);
            $table->string('subject', 500)->nullable();

            $table->timestamp('received_at');

            $table->boolean('has_html')->default(false);
            $table->boolean('has_text')->default(true);
            $table->boolean('has_attachments')->default(false);
            $table->unsignedSmallInteger('attachment_count')->default(0);

            $table->unsignedBigInteger('size_bytes');

            // Pipeline state (e.g. received, parsed, stored); validated via PHP enum.
            $table->string('processing_status')->default('received');

            $table->decimal('spam_score', 5, 2)->nullable();

            $table->json('headers')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('sender_email');
            $table->index('recipient_email');
            $table->index('received_at');
            $table->index('processing_status');
            $table->index(['inbox_id', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
