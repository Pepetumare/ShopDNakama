<?php

namespace App\Notifications;

use App\Notifications\Channels\TransactionalMailChannel;
use App\Services\TransactionalNotifications;
use Illuminate\Notifications\Notification;

class TransactionalEmailVerification extends Notification
{
    public function via(object $notifiable): array
    {
        return [TransactionalMailChannel::class];
    }

    public function enqueue(object $notifiable, TransactionalNotifications $outbox): void
    {
        $outbox->queue('verify_email', $notifiable->getEmailForVerification(),
            ['user_id' => $notifiable->getKey()], key: 'verify-email:'.$notifiable->getKey().':'.now()->timestamp);
    }
}
