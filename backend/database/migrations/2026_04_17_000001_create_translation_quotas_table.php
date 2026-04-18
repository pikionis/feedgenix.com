<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_quotas', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);
            $table->smallInteger('year');
            $table->smallInteger('month');
            $table->bigInteger('chars_used')->default(0);
            $table->bigInteger('chars_limit')->default(0);
            $table->decimal('cost_eur', 10, 4)->default(0);
            $table->timestamps();

            $table->unique(['provider', 'year', 'month'], 'idx_quota_provider_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_quotas');
    }
};
