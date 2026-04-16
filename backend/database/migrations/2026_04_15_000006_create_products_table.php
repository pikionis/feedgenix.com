<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('external_id', 255)->nullable();
            $table->integer('row_number')->nullable();
            $table->json('source_data');
            $table->string('content_hash', 64);
            $table->boolean('is_new')->default(true);
            $table->boolean('has_changes')->default(false);
            $table->timestamps();

            $table->index('project_id');
            $table->index('content_hash');
            $table->index(['project_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
