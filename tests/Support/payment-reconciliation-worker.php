<?php

use App\Models\Order;
use App\Services\MercadoPagoService;
use App\Services\PaymentReconciliation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (PHP_SAPI !== 'cli' || getenv('NAKAMA_RESERVATION_RACE') !== '1') {
    exit(2);
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'nakama_com01_test'
    || config('database.connections.mysql.host') !== '127.0.0.1' || config('database.connections.mysql.url')) {
    exit(2);
}
config(['cache.default' => 'database']);

$order = Order::findOrFail($argv[1]);
$provider = new class($order, $argv[3] ?? null) extends MercadoPagoService
{
    public function __construct(private Order $order, private ?string $gate) {}

    public function getPayment(string $id): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Provider called inside a transaction');
        }

        return ['id' => $id, 'status' => 'approved', 'amount' => $this->order->total, 'currency' => 'CLP',
            'preference_id' => $this->order->mercadopago_preference_id, 'external_reference' => $this->order->number,
            'updated_at' => '2026-11-01T12:00:00.125Z'];
    }

    public function searchPayments(Order $order, int $offset): array
    {
        if (DB::transactionLevel() !== 0 || ! $this->gate) {
            throw new LogicException('Unexpected provider search');
        }
        echo "search-started\n";
        flush();
        $deadline = microtime(true) + 15;
        while (! is_file($this->gate)) {
            if (microtime(true) > $deadline) {
                throw new LogicException('Search gate timeout');
            }
            usleep(10000);
        }

        return ['ids' => [], 'next' => null];
    }
};
$app->instance(MercadoPagoService::class, $provider);
$service = $app->make(PaymentReconciliation::class);
if ($argv[2] === 'review') {
    echo $service->reviewOrder($order, 'scheduler')['outcome']."\n";
    exit;
}

DB::connection()->beforeExecuting(function (string $query) {
    if (str_contains($query, 'from `orders`') && str_contains($query, 'for update')) {
        echo "waiting-for-order\n";
        flush();
    }
});

$result = $service->verify($argv[2], 'webhook', (string) Str::uuid());
echo $result['result']."\n";
exit($result['http'] === 200 ? 0 : 3);
