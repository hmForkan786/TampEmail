<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_servers', function (Blueprint $table): void {
            $table->string('operational_status', 32)->default('active')->after('is_active');
            $table->unsignedSmallInteger('health_score')->default(0)->after('last_health_check_at');
            $table->timestamp('drain_started_at')->nullable()->after('health_score');
            $table->unsignedInteger('consecutive_failures')->default(0)->after('drain_started_at');
            $table->timestamp('last_failure_at')->nullable()->after('consecutive_failures');
            $table->unsignedInteger('max_throughput')->nullable()->after('max_inboxes');
            $table->index(
                ['pool_key', 'operational_status', 'is_active', 'health_score'],
                'mail_servers_pool_ha_index',
            );
        });

        // Fresh checks inherit a full score so existing inventory remains selectable.
        $window = max(1, (int) config('mail_servers.health_window_minutes', 10));
        DB::table('mail_servers')
            ->where('is_active', true)
            ->whereNotNull('last_health_check_at')
            ->where('last_health_check_at', '>=', now()->subMinutes($window))
            ->update(['health_score' => 100, 'operational_status' => 'active']);

        DB::table('mail_servers')
            ->where('is_active', false)
            ->where('operational_status', 'active')
            ->update(['operational_status' => 'disabled', 'health_score' => 0]);
    }

    public function down(): void
    {
        Schema::table('mail_servers', function (Blueprint $table): void {
            $table->dropIndex('mail_servers_pool_ha_index');
            $table->dropColumn([
                'operational_status',
                'health_score',
                'drain_started_at',
                'consecutive_failures',
                'last_failure_at',
                'max_throughput',
            ]);
        });
    }
};
