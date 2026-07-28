<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_signing_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 40);
            $table->string('key_id', 120);
            $table->string('algorithm', 30);
            $table->text('secret_encrypted')->nullable();
            $table->text('public_key')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('environment', 20);
            $table->dateTime('valid_from');
            $table->dateTime('valid_until')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'key_id']);
            $table->index(['provider', 'status', 'environment']);
            $table->index(['valid_from', 'valid_until']);
        });

        Schema::create('webhook_replay_nonces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 40);
            $table->char('nonce_hash', 64);
            $table->string('signing_key_id', 120)->nullable();
            $table->char('request_fingerprint', 64);
            $table->dateTime('first_seen_at');
            $table->dateTime('expires_at');
            $table->char('source_ip_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['provider', 'nonce_hash']);
            $table->index('expires_at');
            $table->index(['provider', 'first_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_replay_nonces');
        Schema::dropIfExists('provider_signing_keys');
    }
};
