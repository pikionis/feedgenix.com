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
        // A customer's feed: where its products come from (a URL or uploads) and how to read them.
        Schema::create('feeds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('source', 16);
            $table->string('status', 16)->default('active');
            $table->jsonb('parse_options')->default('{}');
            $table->timestampsTz();
            $table->index(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE feeds ADD CONSTRAINT feeds_source_check CHECK (source IN ('url', 'upload'))");
        DB::statement("ALTER TABLE feeds ADD CONSTRAINT feeds_status_check CHECK (status IN ('active', 'paused'))");
        TenantRls::enable('feeds');

        // Where a URL feed is fetched from. The URL is encrypted: tokens often live in its query.
        Schema::create('feed_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->string('host', 255);
            $table->string('etag', 255)->nullable();
            $table->string('last_modified', 64)->nullable();
            $table->timestampsTz();
            $table->index('tenant_id');
        });
        TenantRls::enable('feed_sources');
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_sources');
        Schema::dropIfExists('feeds');
    }
};
