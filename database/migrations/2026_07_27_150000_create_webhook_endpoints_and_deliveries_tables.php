<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('name', 100);
            $table->string('url', 2048);
            $table->text('secret_encrypted');
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_delivery_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('webhook_endpoint_id')->index();
            $table->string('event_id', 191);
            $table->string('event_type', 100);
            $table->string('status', 32)->index();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('response_excerpt', 512)->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->json('payload');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['webhook_endpoint_id', 'event_id']);
            $table->foreign('webhook_endpoint_id')->references('id')->on('webhook_endpoints')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
