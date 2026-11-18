<?php

use App\Models\Order;
use App\Models\User;
use App\Services\CustomPaymentPending;
use App\Services\CustomPaymentRecovery;
use App\Services\CustomQuoteCheckout;
use App\Services\MercadoPagoService;
use App\Services\PaymentReconciliation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\Net\MPResponse;

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
config(['cache.default' => 'database', 'services.mercadopago.collector_id' => '42']);
$order = Order::findOrFail($argv[1]);
$mode = $argv[2];
$gate = $argv[3];
$admin = isset($argv[4]) ? User::findOrFail($argv[4]) : null;
$wait = function (string $signal) use ($gate) {
    echo $signal."\n";
    flush();
    $deadline = microtime(true) + 20;
    while (! is_file($gate)) {
        if (microtime(true) > $deadline) {
            throw new LogicException('Controlled gate timeout');
        }
        usleep(10000);
    }
};
$provider = new class($order->id, $mode, $wait) extends MercadoPagoService
{
    public function __construct(private int $orderId, private string $mode, private Closure $wait) {}

    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Provider called inside a database transaction');
        }
    }

    public function createPreference(Order $order): array
    {
        throw new LogicException('No publication is allowed in findings regressions');
    }

    public function getPayment(string $id): array
    {
        $this->outsideTransaction();
        $order = Order::findOrFail($this->orderId);

        return ['id' => $id, 'external_reference' => $order->number, 'amount' => $order->total, 'currency' => 'CLP',
            'status' => $this->mode === 'refund' || $this->mode === 'refund-review' ? 'refunded' : 'approved',
            'preference_id' => $this->mode === 'discrepant' ? 'other-pref-'.$order->id : $order->mercadopago_preference_id,
            'updated_at' => now()->toIso8601String()];
    }

    public function customPreference(string $id): array
    {
        $this->outsideTransaction();
        if ($id === 'unverified-old') {
            throw new MPApiException('Controlled missing candidate', new MPResponse(404, []));
        }
        if ($this->mode === 'admin-recover' || $this->mode === 'recover-held') {
            ($this->wait)('preference-read-held');
        }
        $order = Order::findOrFail($this->orderId);
        $intent = $order->customPaymentIntent;

        return ['id' => $id, 'init_point' => 'https://www.mercadopago.cl/findings/'.$order->id,
            'external_reference' => $order->number, 'metadata' => ['order_id' => $order->id, 'custom_payment_intent' => $intent->token],
            'collector_id' => 42, 'items' => $intent->snapshot['items'], 'shipments' => ['cost' => $order->shipping_cost]];
    }

    public function searchPayments(Order $order, int $offset): array
    {
        $this->outsideTransaction();
        if ($this->mode === 'scheduler-held') {
            ($this->wait)('payment-search-held');
        }

        return ['ids' => [], 'next' => null];
    }
};
$app->instance(MercadoPagoService::class, $provider);
if ($mode === 'discrepant') {
    DB::connection()->beforeExecuting(function (string $sql) {
        if (str_contains($sql, 'from `custom_requests`') && str_contains($sql, 'for update')) {
            echo "request-lock-started\n";
            flush();
        }
    });
}
if ($mode === 'url') {
    $requests = 0;
    DB::connection()->beforeExecuting(function (string $sql) use (&$requests, $wait) {
        if (str_contains($sql, 'from `custom_requests`') && str_contains($sql, 'for update') && ++$requests === 3) {
            // The query has not executed; no commercial row is locked at this barrier.
            $wait('before-url-decision');
        }
    });
    try {
        echo $app->make(CustomQuoteCheckout::class)->checkoutUrl($order, $order->user, $provider)."\n";
    } catch (CustomPaymentPending|ValidationException) {
        echo "url-held\n";
    }
} elseif (in_array($mode, ['discrepant', 'approve', 'refund'], true)) {
    $result = $app->make(PaymentReconciliation::class)->verify((string) ($order->id * 10 + 1), 'webhook', (string) Str::uuid());
    echo $result['result']."\n";
} elseif ($mode === 'admin-recover') {
    if (! $admin?->isAdmin()) {
        exit(2);
    }
    $recovery = $app->make(CustomPaymentRecovery::class);
    $recovery->annotate($order, $admin, 'Concurrent verified candidate evidence');
    echo $recovery->recover($order, $provider, 'admin', $admin->id, 'findings-pref-'.$order->id, 'Concurrent verified candidate evidence')."\n";
} elseif ($mode === 'recover-held') {
    echo $app->make(CustomPaymentRecovery::class)->recover($order, $provider, 'scheduler')."\n";
} else {
    $result = $app->make(PaymentReconciliation::class)->reviewOrder($order, $mode === 'scheduler-held' ? 'scheduler' : 'admin', $admin?->id);
    echo $result['outcome']."\n";
}
