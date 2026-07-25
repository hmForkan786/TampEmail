<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->boolean('outbound_enabled')->default(false)->after('is_healthy');
        });

        Schema::create('outbound_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('inbox_id')->constrained('inboxes')->cascadeOnDelete();
            $table->foreignUuid('source_email_id')->nullable()->constrained('emails')->nullOnDelete();

            $table->string('operation', 32);
            $table->string('state', 32);
            $table->string('idempotency_key', 128);
            $table->string('request_fingerprint', 64);

            $table->string('from_address', 255);
            $table->string('from_display_name', 255)->nullable();

            $table->json('to_recipients');
            $table->json('cc_recipients')->nullable();
            $table->json('bcc_recipients')->nullable();

            $table->string('subject', 998)->nullable();
            $table->longText('text_body')->nullable();
            $table->longText('html_body')->nullable();

            $table->string('in_reply_to', 998)->nullable();
            $table->text('references')->nullable();

            $table->string('provider', 64)->nullable();
            $table->string('provider_message_id', 255)->nullable();

            $table->unsignedSmallInteger('attempt_count')->default(0);

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sending_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->string('failure_code', 80)->nullable();
            $table->string('failure_message', 255)->nullable();

            $table->json('attachment_ids')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key'], 'outbound_messages_user_idempotency_unique');
            $table->index(['state', 'queued_at']);
            $table->index(['inbox_id', 'created_at']);
            $table->index(['operation', 'state']);
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_messages');

        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn('outbound_enabled');
        });
    }
};
