<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Platform feature catalog for entitlement-based plan limits.
     * Plan-to-feature mappings are added in later migrations.
     */
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Stable machine-readable identifier (e.g. max_inboxes, api_access).
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 100)->nullable();

            // Declares how plan values are interpreted (boolean, integer, string, etc.).
            $table->string('value_type')->default('boolean');
            $table->json('default_value')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('category');
            $table->index('is_active');
            $table->index(['is_active', 'display_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
