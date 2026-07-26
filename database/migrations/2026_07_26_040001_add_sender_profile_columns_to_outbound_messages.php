<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table) {
            $table->foreignUuid('sender_profile_id')
                ->nullable()
                ->after('from_display_name')
                ->constrained('outbound_sender_profiles')
                ->nullOnDelete();
            $table->string('reply_to_address', 320)->nullable()->after('sender_profile_id');
            $table->string('reply_to_name', 255)->nullable()->after('reply_to_address');

            $table->index('sender_profile_id', 'outbound_messages_sender_profile_index');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table) {
            $table->dropIndex('outbound_messages_sender_profile_index');
            $table->dropConstrainedForeignId('sender_profile_id');
            $table->dropColumn(['reply_to_address', 'reply_to_name']);
        });
    }
};
