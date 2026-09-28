<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Trusted extensions the application role (a database owner, never a superuser) may create itself.
 * pgvector is not trusted: it is created by provisioning (servers) or in template1 (CI).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    public function down(): void
    {
        // Extensions stay: other objects may depend on them, and they hold no data.
    }
};
