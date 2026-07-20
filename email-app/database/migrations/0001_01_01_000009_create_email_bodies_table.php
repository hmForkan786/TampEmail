<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Email body content stored separately from email metadata (one body per email).
     */
    public function up(): void
    {
        Schema::create('email_bodies', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('email_id')
                ->unique()
                ->constrained('emails')
                ->cascadeOnDelete();

            $table->longText('html_body')->nullable();
            $table->longText('text_body')->nullable();

            $table->string('body_hash', 64)->nullable();
            $table->string('compression', 30)->nullable();

            $table->string('storage_driver')->default('database');

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('body_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_bodies');
    }
};
