<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A plan can carry on by itself (ADR 0021): after each new file is read, its new and changed texts
 * are translated without waiting for a person. Off until someone turns it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translation_plans', function (Blueprint $table): void {
            $table->boolean('auto_translate')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('translation_plans', function (Blueprint $table): void {
            $table->dropColumn('auto_translate');
        });
    }
};
