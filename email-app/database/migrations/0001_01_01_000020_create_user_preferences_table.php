<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Per-user application preferences and personalization settings (one row per user).
     */
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('language', 10)->default('en');
            $table->string('timezone', 100)->default('UTC');

            // UI theme (system, light, dark); validated via PHP enum.
            $table->string('theme', 20)->default('system');

            $table->unsignedInteger('auto_refresh_seconds')->default(15);

            // Default inbox type for new inboxes; validated via PHP enum.
            $table->string('default_inbox_type', 30)->default('temporary');

            $table->json('notification_settings')->nullable();
            $table->json('privacy_settings')->nullable();
            $table->json('ui_settings')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
