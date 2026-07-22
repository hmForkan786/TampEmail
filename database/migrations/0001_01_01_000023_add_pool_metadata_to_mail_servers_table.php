<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds pool entitlement metadata to mail servers.
     * pool_key is nullable until backfill; max_inboxes null means unlimited.
     */
    public function up(): void
    {
        Schema::table('mail_servers', function (Blueprint $table) {
            $table->string('pool_key')->nullable()->after('priority');
            $table->unsignedInteger('max_inboxes')->nullable()->after('pool_key');

            $table->index('pool_key');
            $table->index(['pool_key', 'is_active', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_servers', function (Blueprint $table) {
            $table->dropIndex(['pool_key', 'is_active', 'priority']);
            $table->dropIndex(['pool_key']);
            $table->dropColumn(['pool_key', 'max_inboxes']);
        });
    }
};
