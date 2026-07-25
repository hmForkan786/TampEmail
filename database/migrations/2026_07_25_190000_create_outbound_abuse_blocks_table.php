<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_abuse_blocks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('state', 32);
            $table->string('reason_code', 64);
            $table->string('source', 32)->default('system');
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('cleared_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'state', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_abuse_blocks');
    }
};
