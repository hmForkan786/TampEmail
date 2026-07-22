<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('audit_log_holds', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->foreignUuid('audit_log_id')->constrained('audit_logs')->restrictOnDelete();
        $table->foreignUuid('held_by_user_id')->constrained('users')->restrictOnDelete();
        $table->string('reason', 500);
        $table->timestamp('held_until')->nullable();
        $table->timestamp('released_at')->nullable();
        $table->foreignUuid('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
        $table->index('audit_log_id'); $table->index('held_by_user_id'); $table->index('released_at'); $table->index('held_until');
    }); }
    public function down(): void { Schema::dropIfExists('audit_log_holds'); }
};
