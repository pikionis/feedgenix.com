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
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email');
            $table->timestampTz('email_verified_at')->nullable();
            // Null for accounts that only ever signed in with Google.
            $table->string('password')->nullable();
            $table->string('google_id', 64)->nullable()->unique();
            $table->string('avatar_url', 500)->nullable();
            $table->string('locale', 16)->default('en');
            // Platform flags: never mass assignable, set with forceFill only.
            $table->boolean('is_staff')->default(false);
            $table->rememberToken();
            $table->timestampsTz();
        });

        // Case-insensitive, unique emails ("Alice@x.com" and "alice@x.com" are one account).
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE (email)');

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });
        DB::statement('ALTER TABLE password_reset_tokens ALTER COLUMN email TYPE citext');

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
