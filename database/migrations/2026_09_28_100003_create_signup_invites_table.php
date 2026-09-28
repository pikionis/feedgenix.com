<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Invite codes for the private beta. Only a SHA-256 hash of the code is stored; the code itself is
 * shown once, to the staff member who created it. Platform-level data: no tenant_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signup_invites', function (Blueprint $table): void {
            $table->id();
            $table->char('code_hash', 64)->unique();
            // When set, only this address may use the code.
            $table->string('email')->nullable();
            $table->string('note', 120)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE signup_invites ALTER COLUMN email TYPE citext');
    }

    public function down(): void
    {
        Schema::dropIfExists('signup_invites');
    }
};
