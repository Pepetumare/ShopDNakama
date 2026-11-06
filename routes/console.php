<?php

use Illuminate\Foundation\Inspiring;
use App\Models\Order;
use App\Services\PaymentReconciliation;
use App\Services\StockReservations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('stock:expire-reservations', function (StockReservations $reservations) {
    $this->info('Reservas vencidas: '.$reservations->expire());
})->purpose('Marcar reservas vencidas sin liberar pagos pendientes de conciliación');

Schedule::command('stock:expire-reservations')->everyMinute()->withoutOverlapping();

Artisan::command('payments:reconcile', function (PaymentReconciliation $reconciliation) {
    $this->info('Pedidos revisados: '.$reconciliation->reviewBatch());
})->purpose('Consultar pagos e incidencias de Mercado Pago sin ejecutar operaciones financieras');

Schedule::command('payments:reconcile')->everyFifteenMinutes()->withoutOverlapping(15);

Artisan::command('stock:release-reservation {order} {--verified} {--reason=}', function (StockReservations $reservations) {
    $reason = trim((string) $this->option('reason'));
    if (! $this->option('verified') || $reason === '' || mb_strlen($reason) > 255) {
        $this->error('Confirma la conciliación en el proveedor con --verified y registra el motivo con --reason.');

        return 1;
    }

    $order = Order::where('number', $this->argument('order'))->firstOrFail();
    $this->info('Reservas liberadas: '.$reservations->releaseReconciled($order, $reason));

    return 0;
})->purpose('Liberar reservas vencidas tras verificar el cierre del intento de pago');
