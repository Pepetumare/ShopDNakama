<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transactional_notifications')) {
            Schema::create('transactional_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
                $table->string('recipient');
                $table->string('type', 80);
                $table->json('payload')->nullable();
                $table->string('unique_key')->unique();
                $table->string('status', 20)->default('queued');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->string('message_id')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('processing_at')->nullable();
                $table->timestamp('last_dispatched_at')->nullable();
                $table->uuid('lease_token')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->foreignId('retry_of_id')->nullable()->constrained('transactional_notifications')->nullOnDelete();
                $table->timestamps();
                $table->index(['status', 'last_dispatched_at']);
                $table->index(['order_id', 'type']);
            });
        }

        if (! Schema::hasTable('transactional_notification_events')) {
            Schema::create('transactional_notification_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('notification_id')->constrained('transactional_notifications')->cascadeOnDelete();
                $table->string('event', 40);
                $table->text('message')->nullable();
                $table->json('context')->nullable();
                $table->timestamps();
                $table->index(['notification_id', 'created_at', 'tne_notification_created_idx']);
            });
        }

        if (! Schema::hasIndex(
            'transactional_notification_events',
            'tne_notification_created_idx'
        )) {
            Schema::table('transactional_notification_events', function (Blueprint $table) {
                $table->index(
                    ['notification_id', 'created_at'],
                    'tne_notification_created_idx'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transactional_notification_events');
        Schema::dropIfExists('transactional_notifications');
    }
};
