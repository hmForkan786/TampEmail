<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_sender_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('inbox_id')->constrained('inboxes')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('display_name', 255)->nullable();
            $table->string('reply_to_address', 320)->nullable();
            $table->string('reply_to_name', 255)->nullable();
            $table->mediumText('signature_text')->nullable();
            $table->mediumText('signature_html')->nullable();
            $table->boolean('include_on_send')->default(true);
            $table->boolean('include_on_reply')->default(true);
            $table->boolean('include_on_forward')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'inbox_id'], 'outbound_sender_profiles_user_inbox_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_sender_profiles');
    }
};
