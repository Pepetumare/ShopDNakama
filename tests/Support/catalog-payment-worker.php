<?php

use App\Models\Order;
use App\Services\MercadoPagoService;
use App\Services\PaymentReconciliation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli' || getenv('NAKAMA_RESERVATION_RACE') !== '1') {
    exit(2);
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.host') !== '127.0.0.1'
    || (int) config('database.connections.mysql.port') !== 33317 || config('database.connections.mysql.url')
    || DB::connection()->getDatabaseName() !== 'nakama_com01_test'
    || ! str_contains(DB::selectOne('select version() as v')->v, 'MariaDB')) {
    exit(2);
}
config(['cache.default' => 'array']);
$order = Order::findOrFail($argv[1]);
$mode = $argv[2];
$marker = $argv[3];
$gate = $argv[4];
$source = $argv[5];
$provider = new class($order, $mode, $marker, $gate) extends MercadoPagoService
{
    public function __construct(private Order $order, private string $mode, private string $marker, private string $gate) {}

    public function searchPayments(Order $order, int $offset): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Search performed with database locks held');
        }
        file_put_contents($this->marker, $offset.PHP_EOL, FILE_APPEND | LOCK_EX);
        if (in_array($this->mode, ['block', 'block-extra'], true)) {
            echo "search_entered\n";
            flush();
            $deadline = microtime(true) + 15;
            while (! file_exists($this->gate)) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Barrier timeout');
                }
                usleep(10000);
            }
        }
        $ids = array_map(fn ($i) => (string) (1000000 + $order->id * 1000 + $i), range(1, 25));
        $page = array_slice($ids, $offset, 10);
        if ($this->mode === 'block-extra') {
            $page[0] = (string) (1000000 + $order->id * 1000 + 999);
        }

        return ['ids' => $page, 'next' => $offset + count($page) < 25 ? $offset + count($page) : null];
    }

    public function getPayment(string $id): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Payment GET performed with database locks held');
        }
        if ($this->mode === 'crash') {
            exit(19);
        }

        return ['id' => $id, 'external_reference' => $this->order->number,
            'preference_id' => $this->order->mercadopago_preference_id, 'status' => 'pending',
            'currency' => 'CLP', 'amount' => 10000];
    }
};
$app->instance(MercadoPagoService::class, $provider);
$result = $app->make(PaymentReconciliation::class)->reviewOrder($order, $source);
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
