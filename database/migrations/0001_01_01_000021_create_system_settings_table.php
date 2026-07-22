<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Global application configuration stored as flexible key-value settings.
     */
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Setting group (general, mail, security, etc.); validated via PHP enum.
            $table->string('category', 50);

            $table->string('key', 150)->unique();

            $table->json('value')->nullable();

            // Declares how value should be interpreted (string, boolean, json, etc.).
            $table->string('value_type', 30);

            $table->text('description')->nullable();

            $table->boolean('is_public')->default(false);
            $table->boolean('is_editable')->default(true);

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('category');
            $table->index('is_public');
            $table->index(['category', 'is_public']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
