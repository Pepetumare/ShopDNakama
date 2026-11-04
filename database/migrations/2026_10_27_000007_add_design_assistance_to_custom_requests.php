<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_requests', function (Blueprint $table) {
            $table->boolean('design_assistance')->default(false);
            $table->text('design_assistance_notes')->nullable();
            $table->unsignedInteger('design_assistance_fee')->default(0);
            $table->decimal('base_quoted_price', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('custom_requests', function (Blueprint $table) {
            $table->dropColumn(['design_assistance', 'design_assistance_notes', 'design_assistance_fee', 'base_quoted_price']);
        });
    }
};
