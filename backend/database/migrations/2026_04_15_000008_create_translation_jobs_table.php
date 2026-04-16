<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams');
            $table->string('status', 50)->default('queued');
            $table->integer('total_products')->default(0);
            $table->integer('processed_products')->default(0);
            $table->integer('total_translations')->default(0);
            $table->integer('completed_translations')->default(0);
            $table->integer('cached_translations')->default(0);
            $table->integer('failed_translations')->default(0);
            $table->decimal('estimated_cost', 10, 4)->default(0);
            $table->decimal('actual_cost', 10, 4)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_jobs');
    }
};
