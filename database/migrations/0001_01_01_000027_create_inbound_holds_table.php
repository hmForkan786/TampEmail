<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('inbound_holds', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('target_type', 50);
        $table->uuid('target_id');
        $table->foreignUuid('held_by_user_id')->constrained('users')->restrictOnDelete();
        $table->string('reason', 500);
        $table->timestamp('held_until')->nullable();
        $table->timestamp('released_at')->nullable();
        $table->foreignUuid('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
        $table->index(['target_type','target_id']);
        $table->index('held_by_user_id'); $table->index('released_at'); $table->index('held_until');
    }); }
    public function down(): void { Schema::dropIfExists('inbound_holds'); }
};
