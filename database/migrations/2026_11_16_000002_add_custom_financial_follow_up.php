<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_payment_intents', function (Blueprint $table) {
            $table->boolean('financial_review_required')->default(false);
            $table->timestamp('financial_reviewed_at')->nullable();
            $table->timestamp('financial_next_review_at')->nullable()->index();
            $table->uuid('financial_review_token')->nullable();
            $table->string('financial_review_outcome', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('custom_payment_intents', function (Blueprint $table) {
            $table->dropIndex(['financial_next_review_at']);
            $table->dropColumn(['financial_review_required', 'financial_reviewed_at', 'financial_next_review_at', 'financial_review_outcome', 'financial_review_token']);
        });
    }
};
