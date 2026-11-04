<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_requests', function (Blueprint $table) {
            foreach (['project_name', 'piece_type', 'material', 'dimension_unit', 'usage_type'] as $field) {
                $table->string($field)->nullable();
            }
            foreach (['width', 'height', 'depth'] as $field) {
                $table->decimal($field, 12, 3)->nullable();
            }
            $table->text('description')->nullable();
            $table->text('references')->nullable();
        });
        Schema::table('custom_request_files', function (Blueprint $table) {
            $table->string('extension', 10)->nullable();
            $table->string('file_type', 20)->default('reference');
        });
        // Existing reference files retain their physical names and private storage.
        DB::table('custom_request_files')->orderBy('id')->chunkById(200, function ($files) {
            foreach ($files as $file) {
                DB::table('custom_request_files')->where('id', $file->id)->update(['extension' => strtolower(pathinfo($file->stored_path, PATHINFO_EXTENSION))]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('custom_request_files', fn (Blueprint $table) => $table->dropColumn(['extension', 'file_type']));
        Schema::table('custom_requests', fn (Blueprint $table) => $table->dropColumn(['project_name', 'piece_type', 'material', 'dimension_unit', 'usage_type', 'width', 'height', 'depth', 'description', 'references']));
    }
};
