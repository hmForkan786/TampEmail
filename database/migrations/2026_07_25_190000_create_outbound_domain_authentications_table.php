<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_domain_authentications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('domain_id')->constrained('domains')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('state', 32)->default('unconfigured');
            $table->string('ownership_state', 32)->default('unconfigured');
            $table->string('spf_state', 32)->default('unconfigured');
            $table->string('dkim_state', 32)->default('unconfigured');
            $table->string('dmarc_state', 32)->default('unconfigured');
            $table->string('expected_spf', 512)->nullable();
            $table->json('expected_dkim')->nullable();
            $table->string('expected_ownership', 255)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamps();

            $table->unique(['domain_id', 'provider']);
            $table->index(['state', 'next_check_at']);
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_domain_authentications');
    }
};
