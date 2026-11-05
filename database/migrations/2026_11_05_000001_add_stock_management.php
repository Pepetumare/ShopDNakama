<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('minimum_stock')->default(5);
            $table->unsignedBigInteger('stock_version')->default(0);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('stock_restored_at')->nullable();
        });
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->integer('quantity');
            $table->unsignedInteger('before');
            $table->unsignedInteger('after');
            $table->string('reason', 1000);
            $table->string('idempotency_key', 100)->unique();
            $table->timestamps();
            $table->index(['product_id', 'created_at']);
            $table->unique(['order_id', 'product_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('stock_restored_at'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['minimum_stock', 'stock_version']));
    }
};
