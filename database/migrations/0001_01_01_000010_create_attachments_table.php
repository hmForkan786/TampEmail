<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Attachment metadata only; binary content lives on configured storage disks.
     */
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('email_id')
                ->constrained('emails')
                ->cascadeOnDelete();

            $table->string('original_filename', 255);
            $table->string('stored_filename', 255);
            $table->string('mime_type', 150);
            $table->string('extension', 20)->nullable();

            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum_sha256', 64);

            $table->string('storage_disk', 30)->default('local');
            $table->string('storage_path', 500);

            $table->boolean('is_safe')->nullable();

            // Scan pipeline state (e.g. pending, clean, infected); validated via PHP enum.
            $table->string('scan_status', 30)->default('pending');
            $table->timestamp('scanned_at')->nullable();

            $table->unsignedInteger('downloaded_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('mime_type');
            $table->index('checksum_sha256');
            $table->index('scan_status');
            $table->index('created_at');
            $table->index(['email_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
