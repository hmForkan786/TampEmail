<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_launch_canaries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('subject_type', 16);
            $table->uuid('subject_id');
            $table->string('label', 255)->nullable();
            $table->boolean('active')->default(true)->index();
            $table->foreignUuid('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('added_at');
            $table->foreignUuid('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'active'], 'outbound_launch_canaries_subject_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_launch_canaries');
    }
};
