<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* The overage fee is a movement of its own in the ledger (ADR 0028). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE credit_transactions DROP CONSTRAINT credit_transactions_kind_check');
        DB::statement("ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_kind_check CHECK (kind IN ('grant', 'purchase', 'ai_usage', 'overage', 'refund', 'adjustment'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE credit_transactions DROP CONSTRAINT credit_transactions_kind_check');
        DB::statement("ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_kind_check CHECK (kind IN ('grant', 'purchase', 'ai_usage', 'refund', 'adjustment'))");
    }
};
