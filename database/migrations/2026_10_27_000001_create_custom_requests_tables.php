<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('request_number')->unique();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone', 40)->nullable();
            $table->string('product_type')->index();
            foreach (['variant', 'color', 'size', 'print_position', 'dimensions'] as $field) {
                $table->string($field)->nullable();
            }
            $table->unsignedInteger('quantity');
            $table->text('instructions')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('admin_notes')->nullable();
            $table->decimal('quoted_price', 12, 2)->nullable();
            $table->timestamps();
        });
        Schema::create('custom_request_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_request_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_request_files');
        Schema::dropIfExists('custom_requests');
    }
};
