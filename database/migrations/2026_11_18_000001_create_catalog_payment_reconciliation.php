<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_payment_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('generation')->default(1);
            $table->unsignedInteger('offset')->default(0);
            $table->boolean('scan_open')->default(true);
            $table->timestamp('next_discovery_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_run_at')->index();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedInteger('search_failures')->default(0);
            $table->string('last_outcome', 48)->nullable();
            $table->timestamps();
        });
        Schema::create('catalog_payment_work', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('payment_id', 64);
            $table->unsignedBigInteger('verification_version')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedSmallInteger('last_http')->nullable();
            $table->string('last_outcome', 48)->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('next_attempt_at');
            $table->timestamps();
            $table->unique(['order_id', 'payment_id']);
            $table->index(['order_id', 'next_attempt_at', 'id']);
        });
        Schema::create('catalog_payment_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('generation');
            $table->unsignedInteger('offset');
            $table->unsignedInteger('next_offset')->nullable();
            $table->json('targets');
            $table->string('state', 16)->default('pending');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'generation', 'offset']);
            $table->index(['order_id', 'state', 'last_checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_payment_pages');
        Schema::dropIfExists('catalog_payment_work');
        Schema::dropIfExists('catalog_payment_cursors');
    }
};
