<?php

namespace App\Jobs;

use App\Mail\TransactionalMail;
use App\Models\TransactionalNotification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class SendTransactionalNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public ?string $leaseToken = null;

    public function __construct(public int $notificationId) {}

    public function handle(): void
    {
        $lease = (string) Str::uuid();
        $this->leaseToken = $lease;
        $notification = DB::transaction(function () use ($lease) {
            $row = TransactionalNotification::whereKey($this->notificationId)->lockForUpdate()->firstOrFail();
            if ($row->status === 'sent' || ($row->status === 'processing' && $row->processing_at?->gt(now()->subMinutes(10)))) {
                return null;
            }
            $row->forceFill(['status' => 'processing', 'processing_at' => now(), 'lease_token' => $lease]);
            $row->attempts++;
            $row->save();
            $row->events()->create(['event' => 'sending', 'context' => ['attempt' => $row->attempts]]);

            return $row->fresh();
        }, 3);
        if (! $notification) {
            return;
        }

        try {
            $order = $notification->order;
            if ($notification->type === 'shipped' && $order) {
                $order->assertFulfillable();
            }
            $data = array_merge([
                'number' => $order?->number,
                'name' => $order?->customer_name,
                'status' => $order?->status_label,
                'payment_status' => $order?->payment_status_label,
            ], $notification->payload ?? []);
            if (in_array($notification->type, ['verify_email', 'password_reset'], true)) {
                $data = array_merge($data, $this->accountLinkData($notification));
            }
            $sent = Mail::to($notification->recipient)->send(new TransactionalMail($notification->type, $data));
            $updated = TransactionalNotification::whereKey($notification->id)->where('lease_token', $lease)->update([
                'status' => 'sent', 'sent_at' => now(), 'failed_at' => null, 'last_error' => null,
                'message_id' => $sent?->getMessageId(), 'processing_at' => null, 'lease_token' => null,
            ]);
            if ($updated) {
                $notification->events()->create(['event' => 'sent', 'message' => 'El transporte aceptó el mensaje.',
                    'context' => ['message_id' => $sent?->getMessageId()]]);
            }
        } catch (Throwable $error) {
            $failed = $notification->attempts >= $this->tries;
            $updated = TransactionalNotification::whereKey($notification->id)->where('lease_token', $lease)->update([
                'status' => $failed ? 'failed' : 'queued',
                'failed_at' => $failed ? now() : null,
                'processing_at' => null,
                'lease_token' => null,
                'last_error' => mb_substr($error->getMessage(), 0, 4000),
            ]);
            if ($updated) {
                $notification->events()->create(['event' => $failed ? 'failed' : 'attempt_failed',
                    'message' => mb_substr($error->getMessage(), 0, 4000), 'context' => ['attempt' => $notification->attempts]]);
            }
            throw $error;
        }
    }

    private function accountLinkData(TransactionalNotification $notification): array
    {
        $user = User::whereKey($notification->payload['user_id'] ?? null)->firstOrFail();
        $data = $notification->payload ?? [];
        $data['name'] = $user->name;
        $data['url'] = $notification->type === 'verify_email'
            ? URL::temporarySignedRoute('verification.verify', now()->addMinutes((int) config('auth.verification.expire', 60)),
                ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())])
            : URL::route('password.reset', ['token' => $data['token'], 'email' => $user->getEmailForPasswordReset()]);

        return $data;
    }

    public function failed(?Throwable $error): void
    {
        $message = mb_substr($error?->getMessage() ?? 'Error de transporte.', 0, 4000);
        $notification = TransactionalNotification::whereKey($this->notificationId)
            ->where('status', '!=', 'sent')->where('lease_token', $this->leaseToken)->first();
        if ($notification) {
            $notification->update(['status' => 'failed', 'failed_at' => now(), 'last_error' => $message,
                'processing_at' => null, 'lease_token' => null]);
            $notification->events()->create(['event' => 'failed', 'message' => $message]);
        }
    }
}
