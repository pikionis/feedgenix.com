<?php

declare(strict_types=1);

use App\Domain\Tenancy\Database\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The credit ledger (ADR 0005, ADR 0016), in integer millicredits. Every counter moves with one
 * conditional UPDATE, and the CHECK constraints make an overdraft impossible even for a buggy caller.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One wallet per workspace. reserved is the part of balance held by open reservations.
        Schema::create('credit_wallets', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->primary()->constrained()->cascadeOnDelete();
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('reserved')->default(0);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE credit_wallets ADD CONSTRAINT credit_wallets_balance_check CHECK (balance >= 0)');
        DB::statement('ALTER TABLE credit_wallets ADD CONSTRAINT credit_wallets_reserved_check CHECK (reserved >= 0 AND reserved <= balance)');
        TenantRls::enable('credit_wallets');

        // Escrow for one piece of paid work: AI calls consume it, settling charges what was consumed.
        Schema::create('credit_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 128);
            $table->bigInteger('amount');
            $table->bigInteger('consumed')->default(0);
            $table->string('status', 16)->default('open');
            $table->timestampTz('expires_at');
            $table->timestampTz('settled_at')->nullable();
            $table->timestampsTz();
            // One reservation per work unit and attempt.
            $table->unique(['tenant_id', 'reference']);
        });
        DB::statement('ALTER TABLE credit_reservations ADD CONSTRAINT credit_reservations_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE credit_reservations ADD CONSTRAINT credit_reservations_consumed_check CHECK (consumed >= 0 AND consumed <= amount)');
        DB::statement("ALTER TABLE credit_reservations ADD CONSTRAINT credit_reservations_status_check CHECK (status IN ('open', 'settled'))");
        DB::statement("CREATE INDEX credit_reservations_open_expiry ON credit_reservations (expires_at) WHERE status = 'open'");
        TenantRls::enable('credit_reservations');

        // Append-only: every movement of balance, with the balance after it.
        Schema::create('credit_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            // No ON DELETE action: SET NULL would be an UPDATE, which the append-only trigger rejects.
            $table->foreignId('reservation_id')->nullable()->constrained('credit_reservations');
            $table->string('external_ref', 128)->nullable()->unique();
            $table->string('note', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_kind_check CHECK (kind IN ('grant', 'purchase', 'ai_usage', 'refund', 'adjustment'))");
        DB::statement('ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_amount_check CHECK (amount <> 0)');
        DB::statement('ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_balance_after_check CHECK (balance_after >= 0)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION credit_transactions_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'credit_transactions is append-only';
            END;
            $$;
            CREATE TRIGGER credit_transactions_no_update BEFORE UPDATE ON credit_transactions
                FOR EACH ROW EXECUTE FUNCTION credit_transactions_append_only();
            SQL);
        TenantRls::enable('credit_transactions');
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS credit_transactions_append_only()');
        Schema::dropIfExists('credit_reservations');
        Schema::dropIfExists('credit_wallets');
    }
};
