<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('custom_request_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->text('mercadopago_checkout_url')->nullable();
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->text('description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('description'));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('custom_request_id');
            $table->dropColumn('mercadopago_checkout_url');
        });
    }
};
