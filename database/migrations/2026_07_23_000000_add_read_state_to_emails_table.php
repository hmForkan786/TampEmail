<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table): void {
            $table->boolean('is_read')->default(false)->after('processing_status');
            $table->timestamp('read_at')->nullable()->after('is_read');
            $table->index(['inbox_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table): void {
            $table->dropIndex('emails_inbox_id_is_read_index');
            $table->dropColumn(['is_read', 'read_at']);
        });
    }
};
