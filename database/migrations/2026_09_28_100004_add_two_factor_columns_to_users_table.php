<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Two-factor sign-in (TOTP), required for staff. The secret is encrypted with the app key; recovery
 * codes are stored only as SHA-256 hashes; the last accepted time step blocks replays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->jsonb('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestampTz('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->bigInteger('two_factor_last_step')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step']);
        });
    }
};
