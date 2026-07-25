<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_recipient_suppressions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('recipient_hash', 64)->index();
            $table->text('recipient_encrypted')->nullable();
            $table->string('masked_recipient', 320);
            $table->string('scope_type', 32)->default('global');
            $table->uuid('scope_id')->nullable()->index();
            $table->string('reason', 64);
            $table->string('source', 32);
            $table->string('provider', 64)->nullable();
            $table->uuid('source_event_id')->nullable()->index();
            $table->boolean('active')->default(true)->index();
            $table->timestamp('suppressed_at');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('removed_at')->nullable();
            $table->foreignUuid('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['recipient_hash', 'scope_type', 'scope_id', 'active']);
            $table->index(['reason', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_recipient_suppressions');
    }
};
