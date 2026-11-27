<?php

namespace App\Notifications;

use App\Notifications\Channels\TransactionalMailChannel;
use App\Services\TransactionalNotifications;
use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;

class TransactionalResetPassword extends BaseResetPassword
{
    public function via($notifiable): array
    {
        return [TransactionalMailChannel::class];
    }

    public function enqueue(object $notifiable, TransactionalNotifications $outbox): void
    {
        $outbox->queue('password_reset', $notifiable->getEmailForPasswordReset(),
            ['token' => $this->token, 'user_id' => $notifiable->getKey()],
            key: 'password-reset:'.$notifiable->getKey().':'.hash('sha256', $this->token));
    }
}
