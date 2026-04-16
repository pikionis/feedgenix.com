<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams');
            $table->string('action', 100);
            $table->integer('quantity')->default(1);
            $table->json('metadata')->default('{}');
            $table->string('billing_period', 7)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['team_id', 'billing_period'], 'idx_usage_team_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_logs');
    }
};
