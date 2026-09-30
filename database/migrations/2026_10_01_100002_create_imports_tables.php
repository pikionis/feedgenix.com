<?php

declare(strict_types=1);

use App\Domain\Tenancy\Database\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One reading of a feed: the file received, how it was read, and its column profile.
        Schema::create('imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_id')->constrained()->cascadeOnDelete();
            $table->string('origin', 16);
            $table->string('status', 16)->default('queued');
            $table->foreignId('reuses_import_id')->nullable()->constrained('imports')->nullOnDelete();
            $table->string('original_name', 255)->nullable();
            $table->string('staging_path', 512)->nullable();
            $table->bigInteger('received_bytes')->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->string('blob_path', 512)->nullable();
            $table->jsonb('parse_options')->default('{}');
            $table->string('format', 16)->nullable();
            $table->jsonb('containers')->default('[]');
            $table->string('inner_name', 255)->nullable();
            $table->jsonb('encoding')->nullable();
            $table->string('delimiter', 4)->nullable();
            $table->string('xml_item_path', 255)->nullable();
            $table->integer('row_count')->nullable();
            $table->integer('column_count')->nullable();
            $table->char('column_signature', 64)->nullable();
            $table->jsonb('sku')->nullable();
            // json, not jsonb: jsonb reorders object keys, and sample columns must keep the file's order.
            $table->json('sample_rows')->nullable();
            $table->jsonb('warnings')->default('[]');
            $table->string('failure_reason', 32)->nullable();
            $table->text('failure_message')->nullable();
            $table->integer('retry_after_seconds')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'feed_id', 'id']);
        });
        DB::statement("ALTER TABLE imports ADD CONSTRAINT imports_origin_check CHECK (origin IN ('upload', 'fetch', 'reread'))");
        DB::statement("ALTER TABLE imports ADD CONSTRAINT imports_status_check CHECK (status IN ('queued', 'fetching', 'reading', 'profiled', 'unchanged', 'failed'))");
        // One unfinished import per feed, enforced where no race can pass it.
        DB::statement("CREATE UNIQUE INDEX imports_one_unfinished_per_feed ON imports (feed_id) WHERE status IN ('queued', 'fetching', 'reading')");
        TenantRls::enable('imports');

        Schema::create('import_columns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->integer('position');
            $table->text('name');
            $table->integer('filled');
            $table->decimal('fill_rate', 5, 4);
            $table->integer('max_length');
            $table->string('kind', 16);
            $table->jsonb('examples')->default('[]');
            $table->unique(['import_id', 'position']);
            $table->index('tenant_id');
        });
        TenantRls::enable('import_columns');

        // A chunked upload in progress. Chunk files are stored under the session's id, never under
        // a name the client chose (legacy lesson: a crafted upload deleted arbitrary files).
        Schema::create('upload_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_id')->constrained()->cascadeOnDelete();
            $table->string('filename', 255);
            $table->bigInteger('size');
            $table->integer('chunk_size');
            $table->integer('total_chunks');
            $table->string('status', 16)->default('open');
            $table->foreignId('import_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index(['tenant_id', 'feed_id']);
        });
        DB::statement("ALTER TABLE upload_sessions ADD CONSTRAINT upload_sessions_status_check CHECK (status IN ('open', 'completed'))");
        TenantRls::enable('upload_sessions');
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
        Schema::dropIfExists('import_columns');
        Schema::dropIfExists('imports');
    }
};
