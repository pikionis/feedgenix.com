<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The source's login, encrypted (ADR 0030). Its kind stays readable: listing feeds decrypts nothing.
        Schema::table('feed_sources', function (Blueprint $table): void {
            $table->string('auth_type', 16)->nullable();
            $table->text('credentials')->nullable();
        });
        DB::statement("ALTER TABLE feed_sources ADD CONSTRAINT feed_sources_auth_check CHECK ((auth_type IS NULL) = (credentials IS NULL) AND (auth_type IS NULL OR auth_type IN ('password', 'header')))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE feed_sources DROP CONSTRAINT feed_sources_auth_check');
        Schema::table('feed_sources', function (Blueprint $table): void {
            $table->dropColumn(['auth_type', 'credentials']);
        });
    }
};
