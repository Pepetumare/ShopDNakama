<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_request_sequences', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at');
        });
        // Preserve all existing numbers and reserve any previous numeric range.
        $highest = 0;
        DB::table('custom_requests')->select(['id', 'request_number'])->chunkById(200, function ($requests) use (&$highest) {
            foreach ($requests as $request) {
                if (preg_match('/\APER-(\d{1,15})\z/', $request->request_number, $match)) {
                    $highest = max($highest, (int) $match[1]);
                }
            }
        });
        if ($highest > 0) {
            DB::table('custom_request_sequences')->insert(['id' => $highest, 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_request_sequences');
    }
};
