<?php

use App\Models\Order;
use App\Services\CustomPaymentPending;
use App\Services\CustomQuoteCheckout;
use App\Services\MercadoPagoService;
use App\Services\PaymentReconciliation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

if (PHP_SAPI !== 'cli' || getenv('NAKAMA_RESERVATION_RACE') !== '1') {
    exit(2);
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'nakama_com01_test'
    || config('database.connections.mysql.host') !== '127.0.0.1' || (int) config('database.connections.mysql.port') !== 33317
    || config('database.connections.mysql.url')) {
    exit(2);
}
config(['cache.default' => 'database']);
$order = Order::findOrFail($argv[1]);
$mode = $argv[2];
$marker = $argv[3];
$gate = $argv[4];
$provider = new class($mode, $marker, $gate) extends MercadoPagoService
{
    public function __construct(private string $mode, private string $marker, private string $gate) {}

    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Provider called while database locks are held');
        }
    }

    public function createPreference(Order $order): array
    {
        $this->outsideTransaction();
        if ($this->mode === 'crash_before') {
            exit(17);
        }
        file_put_contents($this->marker, "POST\n", FILE_APPEND | LOCK_EX);
        if ($this->mode === 'crash_after') {
            exit(17);
        }
        echo "post-started\n";
        flush();
        $deadline = microtime(true) + 20;
        while (! is_file($this->gate)) {
            if (microtime(true) > $deadline) {
                throw new LogicException('Gate timeout');
            }
            usleep(10000);
        }

        return ['id' => 'race-pref-'.$order->id, 'url' => 'https://www.mercadopago.cl/race/'.$order->id];
    }

    public function customPreferencePage(string $reference, int $offset): array
    {
        $this->outsideTransaction();
        $order = Order::where('number', $reference)->firstOrFail();

        return ['ids' => is_file($this->marker) ? ['race-pref-'.$order->id] : [], 'total' => is_file($this->marker) ? 1 : 0, 'next' => null];
    }

    public function customPreference(string $id): array
    {
        $this->outsideTransaction();
        $order = Order::findOrFail(substr($id, strlen('race-pref-')));
        $intent = $order->customPaymentIntent;

        return ['id' => $id, 'init_point' => 'https://www.mercadopago.cl/race/'.$order->id,
            'external_reference' => $order->number, 'collector_id' => 42,
            'metadata' => ['order_id' => $order->id, 'custom_payment_intent' => $intent->token],
            'items' => $intent->snapshot['items'], 'shipments' => ['cost' => $order->shipping_cost]];
    }

    public function getPayment(string $id): array
    {
        $this->outsideTransaction();
        $order = Order::findOrFail((int) substr($id, 0, -1));

        return ['id' => $id, 'external_reference' => $order->number, 'status' => 'approved', 'amount' => $order->total,
            'currency' => 'CLP', 'preference_id' => 'race-pref-'.$order->id, 'updated_at' => now()->toIso8601String()];
    }
};
$app->instance(MercadoPagoService::class, $provider);
if ($mode === 'crash_persist') {
    Event::listen('eloquent.saving: '.Order::class, function (Order $saving) {
        if ($saving->mercadopago_preference_id) {
            exit(17);
        }
    });
}
if ($mode === 'webhook') {
    $result = $app->make(PaymentReconciliation::class)->verify((string) ($order->id * 10 + 1), 'webhook', (string) Str::uuid());
    echo $result['result']."\n";
    exit($result['http'] === 200 ? 0 : 3);
}
try {
    echo $app->make(CustomQuoteCheckout::class)->checkoutUrl($order, $order->user, $provider)."\n";
} catch (CustomPaymentPending) {
    echo "pending\n";
} catch (ValidationException) {
    echo "blocked\n";
}
