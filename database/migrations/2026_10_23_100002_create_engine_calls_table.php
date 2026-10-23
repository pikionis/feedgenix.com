<?php

declare(strict_types=1);

use App\Domain\Tenancy\Database\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * One row per attempt that reached a provider (ADR 0004), including failed and billed-but-failed
 * ones. cost_usd is internal: it never leaves the server (ADR 0005).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engine_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('credit_reservations')->nullOnDelete();
            $table->string('purpose', 16);
            $table->string('provider', 16);
            $table->string('model', 64);
            $table->string('prompt', 64);
            $table->smallInteger('attempt');
            $table->integer('item_count');
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->integer('thinking_tokens')->default(0);
            $table->integer('cached_input_tokens')->default(0);
            $table->integer('latency_ms');
            $table->string('status', 16);
            $table->string('error_code', 32)->nullable();
            $table->decimal('cost_usd', 18, 10)->default(0);
            $table->bigInteger('millicredits')->default(0);
            // Charged above what the reservation still held: absorbed, never taken from the customer.
            $table->bigInteger('unbilled_millicredits')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement("ALTER TABLE engine_calls ADD CONSTRAINT engine_calls_status_check CHECK (status IN ('ok', 'retryable', 'terminal'))");
        DB::statement('ALTER TABLE engine_calls ADD CONSTRAINT engine_calls_charge_check CHECK (millicredits >= 0 AND unbilled_millicredits >= 0)');
        TenantRls::enable('engine_calls');
    }

    public function down(): void
    {
        Schema::dropIfExists('engine_calls');
    }
};
