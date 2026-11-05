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
        // The SFTP server's key fingerprint, trusted once and insisted on from then on (ADR 0032).
        Schema::table('feed_sources', function (Blueprint $table): void {
            $table->string('host_key', 64)->nullable();
        });
        DB::statement('ALTER TABLE feed_sources DROP CONSTRAINT feed_sources_auth_check');
        DB::statement("ALTER TABLE feed_sources ADD CONSTRAINT feed_sources_auth_check CHECK ((auth_type IS NULL) = (credentials IS NULL) AND (auth_type IS NULL OR auth_type IN ('password', 'header', 'key')))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE feed_sources DROP CONSTRAINT feed_sources_auth_check');
        DB::table('feed_sources')->where('auth_type', 'key')->update(['auth_type' => null, 'credentials' => null]);
        DB::statement("ALTER TABLE feed_sources ADD CONSTRAINT feed_sources_auth_check CHECK ((auth_type IS NULL) = (credentials IS NULL) AND (auth_type IS NULL OR auth_type IN ('password', 'header')))");
        Schema::table('feed_sources', function (Blueprint $table): void {
            $table->dropColumn('host_key');
        });
    }
};
