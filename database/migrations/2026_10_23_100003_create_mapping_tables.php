<?php

declare(strict_types=1);

use App\Domain\Tenancy\Database\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * How a feed's columns become canonical fields (ADR 0018). A mapping belongs to a feed and a column
 * signature, never to a file (legacy D6: a mapping was reused across uploads with other columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_id')->constrained()->cascadeOnDelete();
            $table->char('column_signature', 64);
            // The profile it was built from; later imports with the same columns reuse it.
            $table->foreignId('import_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('draft');
            $table->string('source_locale', 16)->nullable();
            $table->jsonb('skipped_columns')->default('[]');
            // The AI suggestion in flight or the last one's outcome.
            $table->string('ai_status', 16)->default('none');
            $table->string('ai_failure', 32)->nullable();
            $table->timestampTz('ai_requested_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
            $table->unique(['feed_id', 'column_signature']);
            $table->index('tenant_id');
        });
        DB::statement("ALTER TABLE mappings ADD CONSTRAINT mappings_status_check CHECK (status IN ('draft', 'approved'))");
        DB::statement("ALTER TABLE mappings ADD CONSTRAINT mappings_ai_status_check CHECK (ai_status IN ('none', 'queued', 'running', 'done', 'failed'))");
        TenantRls::enable('mappings');

        // One canonical field (in one locale) and where its value comes from.
        Schema::create('mapping_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mapping_id')->constrained()->cascadeOnDelete();
            $table->string('target', 48);
            $table->string('locale', 16)->nullable();
            $table->string('strategy', 16);
            $table->text('source_column')->nullable();
            $table->jsonb('recipe')->nullable();
            // A proposal that was refused (left empty): what was proposed, for the reviewer. A refused
            // composition keeps its recipe, so a later rebuild judges it again.
            $table->text('candidate_column')->nullable();
            $table->string('origin', 16);
            $table->decimal('confidence', 4, 3)->default(0);
            $table->string('reason', 500)->nullable();
            $table->string('status', 16);
            $table->jsonb('issues')->default('[]');
            $table->timestampsTz();
            $table->index(['mapping_id', 'target']);
            $table->index('tenant_id');
        });
        // One row per field and locale (NULL locale for shared fields counts as one value).
        DB::statement('CREATE UNIQUE INDEX mapping_fields_one_per_target ON mapping_fields (mapping_id, target, COALESCE(locale, \'\'))');
        DB::statement("ALTER TABLE mapping_fields ADD CONSTRAINT mapping_fields_strategy_check CHECK (strategy IN ('direct', 'compose', 'none'))");
        DB::statement("ALTER TABLE mapping_fields ADD CONSTRAINT mapping_fields_origin_check CHECK (origin IN ('heuristic', 'ai', 'manual'))");
        DB::statement("ALTER TABLE mapping_fields ADD CONSTRAINT mapping_fields_status_check CHECK (status IN ('validated', 'mapped', 'needs_input', 'composed', 'rejected', 'cleared'))");
        DB::statement("ALTER TABLE mapping_fields ADD CONSTRAINT mapping_fields_source_check CHECK ((strategy = 'direct' AND source_column IS NOT NULL AND recipe IS NULL) OR (strategy = 'compose' AND source_column IS NULL AND recipe IS NOT NULL) OR (strategy = 'none' AND source_column IS NULL))");
        TenantRls::enable('mapping_fields');
    }

    public function down(): void
    {
        Schema::dropIfExists('mapping_fields');
        Schema::dropIfExists('mappings');
    }
};
