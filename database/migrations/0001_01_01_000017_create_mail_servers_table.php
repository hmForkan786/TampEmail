<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Inbound mail server definitions for the email ingestion pipeline.
     */
    public function up(): void
    {
        Schema::create('mail_servers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name', 100);
            $table->string('hostname', 255);

            // Inbound provider type (e.g. postfix, mailgun); validated via PHP enum.
            $table->string('provider', 50);

            // Ingestion protocol (e.g. smtp, lmtp, api); validated via PHP enum.
            $table->string('protocol', 20);

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(0);

            $table->unsignedInteger('max_connections')->default(100);
            $table->unsignedInteger('timeout_seconds')->default(30);

            $table->timestamp('last_health_check_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('provider');
            $table->index('protocol');
            $table->index('is_active');
            $table->index('priority');
            $table->index(['is_active', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_servers');
    }
};
