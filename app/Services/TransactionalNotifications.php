<?php

namespace App\Services;

use App\Jobs\SendTransactionalNotification;
use App\Models\Order;
use App\Models\TransactionalNotification;
use Illuminate\Support\Facades\DB;

class TransactionalNotifications
{
    public function queue(string $type, string $recipient, array $data = [], ?Order $order = null, ?string $key = null): TransactionalNotification
    {
        if ($order) {
            $data = array_merge([
                'number' => $order->number,
                'name' => $order->customer_name,
                'status' => $order->status_label,
                'payment_status' => $order->payment_status_label,
            ], $data);
        }

        $key ??= $order
            ? $type.':'.$order->id
            : $type.':'.hash('sha256', strtolower($recipient).':'.json_encode($data));

        $notification = TransactionalNotification::firstOrCreate(
            ['unique_key' => $key],
            ['order_id' => $order?->id, 'recipient' => $recipient, 'type' => $type, 'status' => 'queued',
                'queued_at' => now(), 'payload' => $data ?: null]
        );

        if ($notification->wasRecentlyCreated) {
            $notification->events()->create(['event' => 'queued']);
            $notification->forceFill(['last_dispatched_at' => now()])->save();
            SendTransactionalNotification::dispatch($notification->id)->afterCommit();
        }

        return $notification;
    }

    public function retry(TransactionalNotification $notification): TransactionalNotification
    {
        return DB::transaction(function () use ($notification) {
            $failed = TransactionalNotification::whereKey($notification->id)->lockForUpdate()->firstOrFail();
            abort_unless($failed->status === 'failed', 409);
            $retryNumber = TransactionalNotification::where('retry_of_id', $failed->id)->count() + 1;
            $attempt = TransactionalNotification::create([
                'order_id' => $failed->order_id,
                'recipient' => $failed->recipient,
                'type' => $failed->type,
                'unique_key' => 'retry:'.$failed->id.':'.$retryNumber,
                'status' => 'queued',
                'retry_of_id' => $failed->id,
                'queued_at' => now(),
                'payload' => $failed->payload,
            ]);
            $attempt->events()->create(['event' => 'retry_queued', 'context' => ['retry_of_id' => $failed->id]]);
            $attempt->forceFill(['last_dispatched_at' => now()])->save();
            SendTransactionalNotification::dispatch($attempt->id)->afterCommit();

            return $attempt;
        }, 3);
    }

    public function dispatchPending(int $limit = 100): int
    {
        return DB::transaction(function () use ($limit) {
            $stale = now()->subMinutes(15);
            $notifications = TransactionalNotification::query()
                ->where(function ($query) {
                    $query->where('status', 'queued')
                        ->orWhere(fn ($query) => $query->where('status', 'processing')->where('processing_at', '<=', now()->subMinutes(10)));
                })
                ->where(fn ($query) => $query->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', $stale))
                ->orderBy('id')->limit($limit)->lockForUpdate()->get();

            foreach ($notifications as $notification) {
                $notification->forceFill(['last_dispatched_at' => now()])->save();
                $notification->events()->create(['event' => 'redispatched']);
                SendTransactionalNotification::dispatch($notification->id)->afterCommit();
            }

            return $notifications->count();
        }, 3);
    }
}
