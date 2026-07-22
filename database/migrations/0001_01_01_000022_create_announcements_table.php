<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Global announcements, alerts, and maintenance notices for the application.
     */
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('title', 200);
            $table->string('slug', 220)->unique();

            $table->longText('content');

            // Visual severity (info, warning, maintenance, etc.); validated via PHP enum.
            $table->string('type', 30);

            // Audience scope (all, guest, premium, etc.); validated via PHP enum.
            $table->string('target', 30);

            $table->boolean('is_active')->default(true);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->boolean('dismissible')->default(true);
            $table->unsignedInteger('priority')->default(0);

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('type');
            $table->index('target');
            $table->index('is_active');
            $table->index('starts_at');
            $table->index('ends_at');
            $table->index(['is_active', 'starts_at']);
            $table->index(['is_active', 'ends_at']);
            $table->index(['target', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
