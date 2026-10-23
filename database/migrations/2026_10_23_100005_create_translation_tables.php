<?php

declare(strict_types=1);

use App\Domain\Tenancy\Database\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Translation (ADR 0020): what a feed translates into which languages, the translation memory, the
 * work units the pipeline runs, and the products counted against the plan's allowance.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A feed's choices: field => translate, keep or skip; and the target languages.
        Schema::create('translation_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_id')->unique()->constrained()->cascadeOnDelete();
            $table->jsonb('fields')->default('{}');
            $table->jsonb('target_locales')->default('[]');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index('tenant_id');
        });
        TenantRls::enable('translation_plans');

        // Translations that passed the quality checks, reused for the same text at no cost. The
        // field is part of the key: the same words follow other rules in a title and a category.
        Schema::create('translation_memory', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('field', 48);
            $table->string('source_locale', 16);
            $table->string('target_locale', 16);
            $table->char('source_hash', 64);
            $table->text('value');
            $table->string('prompt', 64)->nullable();
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('hits')->default(0);
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'field', 'source_locale', 'target_locale', 'source_hash']);
        });
        TenantRls::enable('translation_memory');

        // One piece of AI work: a batch of texts of one field from one language into another.
        Schema::create('ai_work_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('pipeline_runs')->cascadeOnDelete();
            $table->foreignId('feed_id')->constrained()->cascadeOnDelete();
            $table->string('field', 48);
            $table->string('source_locale', 16);
            $table->string('target_locale', 16);
            // product id => the source hash it must be translated from (a later write is fenced by it).
            $table->jsonb('items');
            $table->unsignedSmallInteger('depth')->default(0);
            $table->string('status', 16)->default('pending');
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_until')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('reservation_id')->nullable()->constrained('credit_reservations')->nullOnDelete();
            $table->string('hold_reason', 32)->nullable();
            $table->string('failure_reason', 32)->nullable();
            $table->timestampTz('available_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status']);
            $table->index(['run_id', 'status']);
        });
        DB::statement("ALTER TABLE ai_work_units ADD CONSTRAINT ai_work_units_status_check CHECK (status IN ('pending', 'leased', 'done', 'failed', 'held', 'split'))");
        TenantRls::enable('ai_work_units');

        // A product counts once per allowance period when it needs AI work (ADR 0005).
        Schema::create('usage_products', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->boolean('overage')->default(false);
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['tenant_id', 'period_start', 'product_id']);
        });
        TenantRls::enable('usage_products');
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_products');
        Schema::dropIfExists('ai_work_units');
        Schema::dropIfExists('translation_memory');
        Schema::dropIfExists('translation_plans');
    }
};
