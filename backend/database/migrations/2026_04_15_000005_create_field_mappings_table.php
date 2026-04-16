<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('upload_id')->nullable()->constrained('uploads');
            $table->json('mapping')->default('{}');
            $table->json('translatable_fields')->default('[]');
            $table->json('protected_fields')->default('[]');
            $table->json('skipped_fields')->default('[]');
            $table->json('ambiguous_fields')->default('[]');
            $table->string('detected_source_language', 10)->nullable();
            $table->decimal('ai_confidence', 3, 2)->nullable();
            $table->string('ai_model', 100)->nullable();
            $table->boolean('user_approved')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_mappings');
    }
};
