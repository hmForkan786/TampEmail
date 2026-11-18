<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            // DATETIME avoids legacy MySQL's implicit ON UPDATE behavior for the
            // first TIMESTAMP column. A lifecycle state update must never rewrite
            // the immutable term start.
            $table->dateTime('starts_at')->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('starts_at')->change();
        });
    }
};
