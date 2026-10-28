<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Plan pauses (ADR 0024): what a workspace has beyond its plan's limits is paused, never deleted.
 * A paused feed takes no new files and no AI work, and keeps serving its last exports; a paused
 * language gets no new translations. `applied_plan` is the plan the pauses were last set for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feeds', function (Blueprint $table): void {
            $table->timestampTz('plan_paused_at')->nullable();
        });
        Schema::table('tenant_languages', function (Blueprint $table): void {
            $table->timestampTz('plan_paused_at')->nullable();
        });
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('applied_plan', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('applied_plan');
        });
        Schema::table('tenant_languages', function (Blueprint $table): void {
            $table->dropColumn('plan_paused_at');
        });
        Schema::table('feeds', function (Blueprint $table): void {
            $table->dropColumn('plan_paused_at');
        });
    }
};
