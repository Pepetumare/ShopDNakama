<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 50)->nullable();
            $table->string('shipping_address_line1')->nullable();
            $table->string('shipping_address_line2')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_region')->nullable();
            $table->string('shipping_postal_code', 30)->nullable();
            $table->string('shipping_country')->nullable();
            $table->string('status', 30)->default('pendiente')->index();
            $table->string('payment_method')->nullable();
            $table->string('payment_status', 30)->default('pendiente')->index();
            $table->decimal('subtotal', 12, 2)->unsigned()->default(0);
            $table->decimal('shipping_cost', 12, 2)->unsigned()->default(0);
            $table->decimal('discount_total', 12, 2)->unsigned()->default(0);
            $table->decimal('total', 12, 2)->unsigned()->default(0);
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
