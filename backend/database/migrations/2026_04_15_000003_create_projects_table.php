<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('source_language', 10)->nullable();
            $table->json('target_languages')->default('[]');
            $table->string('status', 50)->default('draft');
            $table->integer('products_count')->default(0);
            $table->timestamp('last_upload_at')->nullable();
            $table->timestamps();

            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
