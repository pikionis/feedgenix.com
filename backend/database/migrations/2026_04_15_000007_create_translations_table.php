<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('field_name', 255);
            $table->text('source_text');
            $table->string('source_hash', 64);
            $table->string('target_language', 10);
            $table->text('translated_text')->nullable();
            $table->string('provider', 100)->nullable();
            $table->decimal('quality_score', 3, 2)->nullable();
            $table->boolean('is_cached')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['source_hash', 'target_language'], 'idx_translations_lookup');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
