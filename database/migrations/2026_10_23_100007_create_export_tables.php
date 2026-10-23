<?php

declare(strict_types=1);

use App\Domain\Tenancy\Database\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Exports (ADR 0007, ADR 0022): a feed's export profiles (preset, dialect, layout, languages), the
 * immutable artifacts each build produces with the one a profile serves, and the feed tokens of
 * the public URLs, stored hashed for lookup and encrypted for display.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('preset', 32);
            $table->string('dialect', 8);
            $table->string('layout', 16);
            $table->jsonb('locales')->default('[]');
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['feed_id', 'name']);
            $table->index('tenant_id');
        });
        DB::statement("ALTER TABLE export_profiles ADD CONSTRAINT export_profiles_dialect_check CHECK (dialect IN ('csv', 'tsv'))");
        DB::statement("ALTER TABLE export_profiles ADD CONSTRAINT export_profiles_layout_check CHECK (layout IN ('wide', 'per_language'))");
        DB::statement("ALTER TABLE export_profiles ADD CONSTRAINT export_profiles_status_check CHECK (status IN ('active', 'paused'))");
        TenantRls::enable('export_profiles');

        // One build of a profile. Its files never change once written; publishing moves a pointer.
        Schema::create('export_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained('export_profiles')->cascadeOnDelete();
            $table->string('status', 16)->default('building');
            $table->string('storage_key', 160)->nullable();
            // [{name, locale, rows, bytes, sha256, gz_bytes, gz_sha256}]
            $table->jsonb('files')->default('[]');
            $table->unsignedInteger('rows')->default(0);
            $table->char('content_hash', 64)->nullable();
            // What could not be written as it is, by reason, with the first examples.
            $table->jsonb('report')->default('{}');
            $table->string('failure_reason', 32)->nullable();
            $table->text('failure_message')->nullable();
            $table->boolean('files_deleted')->default(false);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->index(['profile_id', 'status']);
            $table->index('tenant_id');
        });
        DB::statement("ALTER TABLE export_artifacts ADD CONSTRAINT export_artifacts_status_check CHECK (status IN ('building', 'published', 'superseded', 'unchanged', 'rejected', 'failed'))");
        // One build of a profile at a time, and one published artifact.
        DB::statement("CREATE UNIQUE INDEX export_artifacts_one_building ON export_artifacts (profile_id) WHERE status = 'building'");
        DB::statement("CREATE UNIQUE INDEX export_artifacts_one_published ON export_artifacts (profile_id) WHERE status = 'published'");
        TenantRls::enable('export_artifacts');

        Schema::table('export_profiles', function (Blueprint $table): void {
            $table->foreignId('published_artifact_id')->nullable()->constrained('export_artifacts')->nullOnDelete();
        });

        Schema::create('feed_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained('export_profiles')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            // A rotated token keeps working until then, so channels have time to switch.
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->index(['profile_id', 'expires_at']);
            $table->index('tenant_id');
        });
        TenantRls::enable('feed_tokens');
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_tokens');
        Schema::table('export_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('published_artifact_id');
        });
        Schema::dropIfExists('export_artifacts');
        Schema::dropIfExists('export_profiles');
    }
};
