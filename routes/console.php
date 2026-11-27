<?php

use App\Models\Order;
use App\Services\HeldReservations;
use App\Services\PaymentReconciliation;
use App\Services\StockReservations;
use App\Services\TransactionalNotifications;
use Illuminate\Foundation\Inspiring;
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

Artisan::command('notifications:dispatch-pending', function (TransactionalNotifications $notifications) {
    $count = $notifications->dispatchPending();
    $this->info('Notificaciones recuperadas para envío: '.$count);
})->purpose('Recuperar trabajos de correo transaccional que no llegaron a la cola');

Schedule::command('notifications:dispatch-pending')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('stock:release-reservation {order} {--verified} {--reason=}', function (StockReservations $reservations) {
    $reason = trim((string) $this->option('reason'));
    if (! $this->option('verified') || $reason === '' || mb_strlen($reason) > 255) {
        $this->error('Indica --verified y registra el motivo con --reason. Se comprobará la evidencia guardada.');

        return 1;
    }

    $order = Order::where('number', $this->argument('order'))->firstOrFail();
    $result = $reservations->recover($order, $reason);
    if ($result['hold'] !== null) {
        $this->error($result['hold']);

        return 1;
    }
    $this->info('Reservas liberadas: '.$result['released']);

    return 0;
})->purpose('Liberar reservas vencidas con evidencia de preferencia nunca enviada');

Artisan::command('stock:held-reservations {--after=0}', function (HeldReservations $report) {
    foreach ($report->after((int) $this->option('after')) as $line) {
        $this->line($line);
    }
})->purpose('Listar reservas vencidas y sus motivos. Continuar con --after=ID.');
