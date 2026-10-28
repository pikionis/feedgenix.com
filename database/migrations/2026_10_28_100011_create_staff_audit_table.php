<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * What staff did in the staff area: every request, and every change with its details (ADR 0026).
 * A platform table (no tenant_id, no row-level security): only the staff area reads it. No foreign
 * keys, so the trail outlives the accounts and workspaces it names; append-only, like the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_audit', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('user_email', 255);
            $table->string('action', 32);
            $table->string('method', 8);
            $table->string('path', 255);
            $table->unsignedSmallInteger('status');
            // The workspace the request looked at or changed, when there is one.
            $table->unsignedBigInteger('subject_tenant_id')->nullable();
            $table->jsonb('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['subject_tenant_id', 'id']);
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION staff_audit_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'staff_audit is append-only';
            END;
            $$;
            CREATE TRIGGER staff_audit_no_change BEFORE UPDATE OR DELETE ON staff_audit
                FOR EACH ROW EXECUTE FUNCTION staff_audit_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_audit');
        DB::unprepared('DROP FUNCTION IF EXISTS staff_audit_append_only()');
    }
};
