<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only log of authenticated API requests for analytics, security, and auditing.
     */
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('api_key_id')
                ->nullable()
                ->constrained('api_keys')
                ->nullOnDelete();

            $table->foreignUuid('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('method', 10);
            $table->string('endpoint', 255);

            $table->string('ip_address', 45);
            $table->text('user_agent')->nullable();

            $table->unsignedSmallInteger('response_status');
            $table->unsignedInteger('response_time_ms');

            $table->unsignedInteger('request_size_bytes')->nullable();
            $table->unsignedInteger('response_size_bytes')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('endpoint');
            $table->index('response_status');
            $table->index('created_at');
            $table->index(['api_key_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['endpoint', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
