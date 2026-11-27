<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->string('release_reason', 320)->nullable()->change();
        });
    }

    public function down(): void
    {
        $length = DB::getDriverName() === 'sqlite' ? 'length' : 'char_length';
        if (DB::table('stock_reservations')->whereRaw("$length(release_reason) > 255")->exists()) {
            throw new RuntimeException('La reversión truncaría motivos de liberación registrados.');
        }

        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->string('release_reason', 255)->nullable()->change();
        });
    }
};
