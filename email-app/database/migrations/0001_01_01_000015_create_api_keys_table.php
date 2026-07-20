<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Hashed API credentials for authenticated user access.
     */
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('name', 100);
            $table->string('key_prefix', 20);

            // Hashed secret only; plaintext keys must never be persisted.
            $table->string('key_hash', 255)->unique();

            $table->json('permissions')->nullable();

            $table->unsignedInteger('rate_limit_per_minute')->default(60);

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('key_prefix');
            $table->index('expires_at');
            $table->index('revoked_at');
            $table->index(['user_id', 'revoked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
