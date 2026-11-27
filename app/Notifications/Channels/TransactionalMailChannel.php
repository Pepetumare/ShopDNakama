<?php

namespace App\Notifications\Channels;

use App\Services\TransactionalNotifications;

class TransactionalMailChannel
{
    public function send(object $notifiable, object $notification): void
    {
        $notification->enqueue($notifiable, app(TransactionalNotifications::class));
    }
}
