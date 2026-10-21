<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('mercadopago_payment_id')->nullable()->unique();
            $table->string('mercadopago_preference_id')->nullable()->unique();
            $table->string('delivery_type')->nullable();
            $table->timestamp('stock_deducted_at')->nullable();
            $table->string('fulfillment_issue')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['mercadopago_payment_id', 'mercadopago_preference_id', 'delivery_type', 'stock_deducted_at', 'fulfillment_issue']);
        });
    }
};
