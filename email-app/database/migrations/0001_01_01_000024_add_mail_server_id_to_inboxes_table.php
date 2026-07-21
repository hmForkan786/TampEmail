<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Assigns each inbox to a mail server. Nullable until backfill.
     */
    public function up(): void
    {
        Schema::table('inboxes', function (Blueprint $table) {
            $table->uuid('mail_server_id')->nullable()->after('user_id');

            $table->index('mail_server_id');

            $table->foreign('mail_server_id')
                ->references('id')
                ->on('mail_servers')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inboxes', function (Blueprint $table) {
            $table->dropForeign(['mail_server_id']);
            $table->dropIndex(['mail_server_id']);
            $table->dropColumn('mail_server_id');
        });
    }
};
