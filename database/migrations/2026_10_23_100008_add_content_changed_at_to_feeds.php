<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * When a feed's catalogue last changed in a way its exports show: a file read, a translation run
 * finished, a person's word on a text. The reconciler builds every active export profile whose
 * last build is older (ADR 0022).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feeds', function (Blueprint $table): void {
            $table->timestampTz('content_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('feeds', function (Blueprint $table): void {
            $table->dropColumn('content_changed_at');
        });
    }
};
