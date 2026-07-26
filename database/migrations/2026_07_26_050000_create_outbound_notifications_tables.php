<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_notification_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->boolean('notifications_enabled')->default(true);
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('email_enabled')->default(true);
            $table->json('events');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::create('outbound_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('outbound_message_id')->nullable()->index();
            $table->string('event_type', 64)->index();
            $table->string('idempotency_key', 191);
            $table->json('payload');
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamp('dismissed_at')->nullable()->index();
            $table->timestamp('email_queued_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('outbound_message_id')->references('id')->on('outbound_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_notifications');
        Schema::dropIfExists('outbound_notification_preferences');
    }
};
