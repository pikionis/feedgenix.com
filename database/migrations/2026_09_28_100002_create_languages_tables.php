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
        // Global reference data: no tenant_id.
        Schema::create('languages', function (Blueprint $table): void {
            $table->string('code', 16)->primary(); // BCP-47
            $table->string('name', 64);
            $table->string('native_name', 64);
            $table->string('direction', 3)->default('ltr');
            $table->boolean('active')->default(true);
        });
        DB::statement("ALTER TABLE languages ADD CONSTRAINT languages_direction_check CHECK (direction IN ('ltr', 'rtl'))");

        /** @var array<string, array{0: string, 1: string, 2: string}> $registry */
        $registry = config('languages');
        DB::table('languages')->upsert(
            array_map(
                static fn (string $code, array $language): array => [
                    'code' => $code,
                    'name' => $language[0],
                    'native_name' => $language[1],
                    'direction' => $language[2],
                    'active' => true,
                ],
                array_keys($registry),
                $registry,
            ),
            ['code'],
        );

        // The workspace's target languages; the plan's language limit counts these rows.
        Schema::create('tenant_languages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('language_code', 16);
            $table->foreign('language_code')->references('code')->on('languages');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'language_code']);
        });

        TenantRls::enable('tenant_languages');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_languages');
        Schema::dropIfExists('languages');
    }
};
