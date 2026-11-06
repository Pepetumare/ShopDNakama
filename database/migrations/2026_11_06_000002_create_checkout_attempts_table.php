<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('cart_hash', 64);
            $table->string('delivery_hash', 64)->nullable();
            $table->string('state', 24);
            $table->timestamp('lookup_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_attempts');
    }
};
