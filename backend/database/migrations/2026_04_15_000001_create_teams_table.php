<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->unsignedBigInteger('owner_id');
            $table->string('slug', 255)->unique();
            $table->string('stripe_customer_id')->nullable();
            $table->string('plan', 50)->default('free');
            $table->integer('products_used_this_month')->default(0);
            $table->timestamp('billing_cycle_reset_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
