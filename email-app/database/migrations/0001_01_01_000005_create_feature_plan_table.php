<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('feature_plan', function (Blueprint $table) {
            // Pivot table linking subscription plans to platform features.

            $table->uuid('id')->primary();

            $table->uuid('feature_id');
            $table->uuid('plan_id');

            // Per-plan feature value: boolean, integer, string, or structured JSON.
            $table->json('feature_value')->nullable();

            $table->timestamps();

            $table->index('feature_id');
            $table->index('plan_id');
            $table->unique(['feature_id', 'plan_id']);

            $table->foreign('feature_id')
                ->references('id')
                ->on('features')
                ->cascadeOnDelete();

            $table->foreign('plan_id')
                ->references('id')
                ->on('plans')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feature_plan');
    }
};
