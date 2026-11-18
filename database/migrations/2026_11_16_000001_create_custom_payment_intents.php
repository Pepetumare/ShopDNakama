<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_payment_intents', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('custom_request_id')->unique()->constrained()->restrictOnDelete();
            $table->string('external_reference')->unique();
            $table->string('state', 24)->index();
            $table->json('snapshot');
            $table->boolean('legacy')->default(false);
            $table->timestamp('sending_at')->nullable();
            $table->timestamp('lookup_at')->nullable()->index();
            $table->uuid('lookup_token')->nullable();
            $table->string('preference_id')->nullable()->unique();
            $table->string('checkout_url', 2048)->nullable();
            $table->string('outcome', 64)->nullable();
            $table->unsignedInteger('payment_offset')->default(0);
            $table->timestamps();
        });
        Schema::create('custom_payment_intent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_payment_intent_id')->constrained()->restrictOnDelete();
            $table->string('source', 24);
            $table->string('outcome', 64);
            $table->json('evidence')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at');
        });
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->foreignId('custom_payment_intent_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('custom_payment_intent_id'));
        Schema::dropIfExists('custom_payment_intent_events');
        Schema::dropIfExists('custom_payment_intents');
    }
};
