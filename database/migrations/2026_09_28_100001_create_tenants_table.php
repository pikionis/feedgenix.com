<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Workspaces (tenants) and memberships. These are identity tables, read before a tenant context
 * exists (to find which workspace a user may act in), so they carry no row-level security.
 * Every table that holds a workspace's DATA does (ADR 0002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 64)->unique();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            // Plan and period: written by staff commands and Stripe webhooks only (forceFill).
            $table->string('plan', 16)->default('free');
            $table->string('billing_interval', 8)->nullable();
            $table->string('plan_status', 16)->default('active');
            $table->timestampTz('plan_started_at')->nullable();
            $table->timestampTz('plan_ends_at')->nullable();
            // The allowance clock: monthly slices are anchored here.
            $table->timestampTz('usage_anchor_at')->useCurrent();
            $table->jsonb('limits_override')->nullable();
            $table->boolean('overage_enabled')->default(true);

            // Platform switches.
            $table->timestampTz('ai_paused_at')->nullable();
            $table->string('ai_paused_reason', 32)->nullable();
            $table->boolean('is_internal')->default(false);
            $table->timestampTz('frozen_at')->nullable();

            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_plan_check CHECK (plan IN ('free', 'pro', 'ultra', 'custom'))");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_billing_interval_check CHECK (billing_interval IS NULL OR billing_interval IN ('month', 'year'))");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_plan_status_check CHECK (plan_status IN ('active', 'expired'))");

        Schema::create('tenant_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('member');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'user_id']);
            $table->index('user_id');
        });

        DB::statement("ALTER TABLE tenant_user ADD CONSTRAINT tenant_user_role_check CHECK (role IN ('owner', 'admin', 'member'))");

        Schema::table('users', function (Blueprint $table): void {
            // The workspace a user acts in; membership is re-checked on every request.
            $table->foreignId('current_tenant_id')->nullable()->after('is_staff')->constrained('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_tenant_id');
        });
        Schema::dropIfExists('tenant_user');
        Schema::dropIfExists('tenants');
    }
};
