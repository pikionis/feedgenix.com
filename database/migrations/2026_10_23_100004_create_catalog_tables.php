<?php

declare(strict_types=1);

use App\Domain\Tenancy\Database\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The catalogue (ADR 0003, ADR 0019): products with their golden and pass-through values exactly
 * as the source wrote them, and one row per product × field × language for texts, so sync,
 * translation and a person's edits never rewrite a shared document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_id')->constrained()->cascadeOnDelete();
            $table->text('sku');
            // Golden and pass-through fields: field => the exact source text, byte for byte.
            $table->jsonb('source_values')->default('{}');
            $table->char('values_hash', 64);
            $table->string('status', 16)->default('active');
            $table->foreignId('first_seen_import_id')->nullable()->constrained('imports')->nullOnDelete();
            $table->foreignId('last_seen_import_id')->nullable()->constrained('imports')->nullOnDelete();
            $table->timestampTz('disappeared_at')->nullable();
            $table->timestampsTz();
            $table->unique(['feed_id', 'sku']);
            $table->index(['tenant_id', 'feed_id', 'status']);
        });
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('active', 'disappeared'))");
        TenantRls::enable('products');

        Schema::create('product_texts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('field', 48);
            $table->string('locale', 16);
            // source: from the feed (a column or a composition); target: produced for a language.
            $table->string('role', 8);
            $table->text('value')->nullable();
            $table->string('status', 16);
            $table->string('origin', 16);
            // A source's own hash; a target's: the hash of the source text it was made from.
            $table->char('source_hash', 64)->nullable();
            $table->string('source_locale', 16)->nullable();
            $table->string('prompt', 64)->nullable();
            $table->string('model', 64)->nullable();
            $table->jsonb('checks')->nullable();
            $table->timestampsTz();
            $table->unique(['product_id', 'field', 'locale']);
            $table->index(['tenant_id', 'product_id']);
        });
        DB::statement("ALTER TABLE product_texts ADD CONSTRAINT product_texts_role_check CHECK (role IN ('source', 'target'))");
        DB::statement("ALTER TABLE product_texts ADD CONSTRAINT product_texts_status_check CHECK (status IN ('ready', 'pending', 'stale', 'qa_failed', 'held'))");
        DB::statement("ALTER TABLE product_texts ADD CONSTRAINT product_texts_origin_check CHECK (origin IN ('source', 'composed', 'ai', 'tm', 'manual', 'approved'))");
        // Pending work is found through this index: cost follows the work, not the catalogue.
        DB::statement("CREATE INDEX product_texts_open_work ON product_texts (tenant_id, field, locale) WHERE status IN ('pending', 'stale')");
        TenantRls::enable('product_texts');

        // One run of a feed through the pipeline (ADR 0006): sync, plan, translate, export.
        Schema::create('pipeline_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mapping_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage', 16);
            $table->string('status', 20);
            $table->jsonb('counts')->default('{}');
            $table->jsonb('warnings')->default('[]');
            $table->string('failure_reason', 32)->nullable();
            $table->text('failure_message')->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'feed_id', 'id']);
        });
        DB::statement("ALTER TABLE pipeline_runs ADD CONSTRAINT pipeline_runs_stage_check CHECK (stage IN ('sync', 'plan', 'translate', 'export', 'done'))");
        DB::statement("ALTER TABLE pipeline_runs ADD CONSTRAINT pipeline_runs_status_check CHECK (status IN ('queued', 'running', 'awaiting_decision', 'held', 'done', 'failed'))");
        DB::statement("CREATE UNIQUE INDEX pipeline_runs_one_unfinished_per_feed ON pipeline_runs (feed_id) WHERE status IN ('queued', 'running', 'awaiting_decision', 'held')");
        TenantRls::enable('pipeline_runs');
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_runs');
        Schema::dropIfExists('product_texts');
        Schema::dropIfExists('products');
    }
};
