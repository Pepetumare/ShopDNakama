<?php

use App\Models\CatalogPaymentPage;
use App\Models\Order;
use App\Services\MercadoPagoService;
use App\Services\PaymentReconciliation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('NAKAMA_RESERVATION_RACE') !== '1' || config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1' || (int) config('database.connections.mysql.port') !== 33317
    || DB::connection()->getDatabaseName() !== 'nakama_com01_test') {
    exit(2);
}
config(['cache.default' => 'array']);
[$file, $orderId, $mode, $gate] = $argv;
$pause = function ($label) use ($gate) {
    echo 'worker_pid:'.getmypid()."\n";
    echo $label."\n";
    flush();
    $deadline = microtime(true) + 20;
    while (! is_file($gate)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Gate expired');
        } usleep(10000);
    }
};
if ($mode === 'kill-discovery') {
    Event::listen('eloquent.created: '.CatalogPaymentPage::class, fn () => $pause('discovery_uncommitted'));
}
if ($mode === 'kill-completion') {
    Event::listen('eloquent.updating: '.CatalogPaymentPage::class, function ($page) use ($pause) {
        if ($page->state === 'verified') {
            $pause('completion_uncommitted');
        }
    });
}
$order = Order::findOrFail($orderId);
$provider = new class($order, $mode, $pause) extends MercadoPagoService
{
    public function __construct(private Order $order, private string $mode, private Closure $pause) {}

    public function searchPayments(Order $order, int $offset): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('HTTP inside transaction');
        }

        return ['ids' => [(string) (9000000 + $order->id)], 'next' => null];
    }

    public function getPayment(string $id): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('GET inside transaction');
        }
        if (in_array($this->mode, ['slow-get', 'slow-refund', 'slow-error'], true)) {
            ($this->pause)('get_entered');
        }

        if ($this->mode === 'slow-error') {
            throw new RuntimeException('Late expired-owner GET error');
        }

        return ['id' => $id, 'external_reference' => $this->order->number,
            'preference_id' => $this->order->mercadopago_preference_id, 'currency' => 'CLP', 'amount' => 10000,
            'status' => $this->mode === 'takeover' ? 'approved' : ($this->mode === 'slow-refund' ? 'refunded' : 'pending'),
            'updated_at' => $this->mode === 'takeover' ? '2026-11-18T16:00:00Z' : ($this->mode === 'slow-refund' ? '2026-11-18T17:00:00Z' : '2026-11-18T15:00:00Z')];
    }
};
$app->instance(MercadoPagoService::class, $provider);
echo json_encode($app->make(PaymentReconciliation::class)->reviewOrder($order, 'admin'))."\n";
