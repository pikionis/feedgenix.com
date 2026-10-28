<?php

declare(strict_types=1);

use App\Domain\Tenancy\Database\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Notices (ADR 0025): what a workspace is told about its usage, waiting work, plan pauses and held
 * exports, with the choices it has. `key` makes each notice once (one 80% notice per period).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('key', 160);
            $table->string('level', 8);
            $table->string('title', 160);
            $table->text('body');
            $table->string('action_label', 60)->nullable();
            $table->string('action_path', 200)->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('emailed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'key']);
            $table->index(['tenant_id', 'read_at']);
        });
        DB::statement("ALTER TABLE notices ADD CONSTRAINT notices_level_check CHECK (level IN ('info', 'warn', 'action'))");
        TenantRls::enable('notices');
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
