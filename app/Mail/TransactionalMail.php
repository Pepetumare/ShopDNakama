<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TransactionalMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $kind, public array $data = []) {}

    public function build(): self
    {
        $this->data += ['message' => match ($this->kind) {
            'order_pending' => 'Recibimos tu pedido. El pago aún está pendiente de confirmación.',
            'payment_confirmed' => 'Verificamos tu pago y comenzaremos a procesar el pedido.',
            'preparing' => 'Tu pedido está en preparación.',
            'shipped' => 'Tu pedido fue despachado.',
            'delivered' => 'Tu pedido figura como entregado.',
            'cancelled' => 'Tu pedido fue cancelado.',
            'refunded' => 'El reembolso de tu pedido fue confirmado.',
            default => 'Tenemos una actualización sobre tu pedido.',
        }];
        $subject = match ($this->kind) {
            'order_pending' => 'Recibimos tu pedido '.$this->data['number'],
            'payment_confirmed' => 'Pago confirmado para '.$this->data['number'],
            'preparing' => 'Estamos preparando tu pedido '.$this->data['number'],
            'shipped' => 'Tu pedido '.$this->data['number'].' fue enviado',
            'delivered' => 'Tu pedido '.$this->data['number'].' fue entregado',
            'cancelled', 'refunded' => 'Actualización de tu pedido '.$this->data['number'],
            'verify_email' => 'Verifica tu correo en Shop D. Nakama',
            'password_reset' => 'Restablece tu contraseña de Shop D. Nakama',
            default => 'Actualización de Shop D. Nakama',
        };

        return $this->subject($subject)->view('emails.transactional')->text('emails.transactional-text')
            ->with(['kind' => $this->kind, 'data' => $this->data]);
    }
}
