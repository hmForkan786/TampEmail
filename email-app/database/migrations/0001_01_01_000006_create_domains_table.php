<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Inbound email domains available for temporary mailbox generation.
     */
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('domain', 255)->unique();
            $table->string('display_name', 100);
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->boolean('allow_registration')->default(true);
            $table->boolean('is_healthy')->default(true);

            $table->unsignedInteger('priority')->default(0);
            $table->unsignedInteger('max_mailboxes')->nullable();
            $table->unsignedInteger('retention_hours')->default(24);

            $table->timestamp('dns_verified_at')->nullable();
            $table->timestamp('last_health_check_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('is_public');
            $table->index('priority');
            $table->index(['is_active', 'is_public', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
