<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Disposable inboxes mapped to a domain and optionally owned by a registered user.
     */
    public function up(): void
    {
        Schema::create('inboxes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('domain_id')
                ->constrained('domains')
                ->cascadeOnDelete();

            $table->foreignUuid('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('local_part', 120);
            $table->string('full_address', 255)->unique();
            $table->string('display_name', 120)->nullable();

            // Inbox lifecycle classification (e.g. temporary, reserved); validated via PHP enum.
            $table->string('inbox_type')->default('temporary');

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_received_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);

            $table->boolean('is_active')->default(true);

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('expires_at');
            $table->index('last_received_at');
            $table->index('is_active');
            $table->index(['user_id', 'is_active']);
            $table->index(['domain_id', 'local_part']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inboxes');
    }
};
