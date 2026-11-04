<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_requests', function (Blueprint $table) {
            $table->string('shape', 40)->nullable();
            $table->string('border_color', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('custom_requests', fn (Blueprint $table) => $table->dropColumn(['shape', 'border_color']));
    }
};
