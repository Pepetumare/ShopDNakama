<?php

use App\Models\Order;
use App\Services\MercadoPagoService;
use App\Services\PaymentReconciliation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || getenv('NAKAMA_RESERVATION_RACE') !== '1' || config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1' || (int) config('database.connections.mysql.port') !== 33317
    || config('database.connections.mysql.url') || DB::connection()->getDatabaseName() !== 'nakama_com01_test'
    || ! str_contains(DB::selectOne('select @@datadir as d')->d, 'aud004')) {
    exit(2);
}
config(['cache.default' => 'array']);
$order = Order::findOrFail($argv[1]);
$fail = $argv[2] === 'fail';
$provider = new class($order, $fail) extends MercadoPagoService
{
    public function __construct(private Order $order, private bool $fail) {}

    public function searchPayments(Order $order, int $offset): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Search inside transaction');
        }
        $ids = array_map(fn ($i) => (string) (70000000 + $order->id * 1000 + $i), range(1, 11));
        $rows = array_slice($ids, $offset, 10);

        return ['ids' => $rows, 'next' => $offset + count($rows) < 11 ? $offset + count($rows) : null];
    }

    public function getPayment(string $id): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Detail GET inside transaction');
        }
        $base = 70000000 + $this->order->id * 1000;
        if ($id === (string) ($base + 2) && $this->fail) {
            throw new RuntimeException('Isolated custom detail failure');
        }

        return ['id' => $id, 'external_reference' => $this->order->number,
            'preference_id' => $this->order->mercadopago_preference_id, 'currency' => 'CLP', 'amount' => 10000,
            'status' => $id === (string) ($base + 11) ? 'approved' : 'rejected'];
    }
};
$app->instance(MercadoPagoService::class, $provider);
echo json_encode($app->make(PaymentReconciliation::class)->reviewOrder($order, 'scheduler')).PHP_EOL;
