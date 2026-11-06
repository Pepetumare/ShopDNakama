<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 24);
            $table->string('provider_payment_id', 64);
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('checkout_attempt_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('preference_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->string('provider_status', 32);
            $table->string('incident', 40)->nullable();
            $table->timestamp('approved_at', 3)->nullable();
            $table->timestamp('provider_updated_at', 3)->nullable();
            $table->timestamp('last_verified_at');
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('correlation_id');
            $table->timestamps();
            $table->unique(['provider', 'provider_payment_id']);
            $table->index(['last_verified_at', 'id']);
        });

        Schema::create('payment_reconciliation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('provider_payment_id', 64)->nullable();
            $table->string('source', 24);
            $table->string('outcome', 48);
            $table->string('provider_status', 32)->nullable();
            $table->uuid('correlation_id');
            $table->text('note')->nullable();
            $table->timestamp('created_at');
            $table->index(['order_id', 'source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_events');
        Schema::dropIfExists('payment_transactions');
    }
};
