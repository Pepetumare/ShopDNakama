<?php

use App\Http\Controllers\CheckoutController;
use App\Models\User;
use App\Services\MercadoPagoService;
use App\Services\ShoppingCart;
use App\Services\StockReservations;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

$user = User::findOrFail($argv[1]);
$request = Request::create('/checkout', 'POST', [
    'customer_name' => 'Test', 'customer_email' => 'buyer@example.test', 'customer_phone' => '123456789',
    'shipping_address_line1' => 'Test 123', 'shipping_city' => 'Santiago',
    'shipping_region' => 'Metropolitana', 'delivery_type' => 'despacho',
]);
$request->setUserResolver(fn () => $user);
$controller = $app->make(CheckoutController::class);
$reservations = $app->make(StockReservations::class);
$sameAttempt = isset($argv[2]);
$token = $argv[2] ?? $controller->index($request, $app->make(ShoppingCart::class), $reservations)->getData()['attempt']->token;
$request->merge(['attempt' => $token]);

DB::connection()->beforeExecuting(function (string $query) use ($sameAttempt) {
    $table = $sameAttempt ? 'users' : 'products';
    if (str_contains($query, 'from `'.$table.'`') && str_contains($query, 'for update')) {
        echo "waiting-for-lock\n";
        flush();
    }
});

$provider = new class extends MercadoPagoService
{
    public function createPreference(\App\Models\Order $order): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Provider called inside a transaction');
        }
        echo "provider-create\n";
        usleep(300000);

        return ['id' => 'fake-'.$order->number, 'url' => 'https://example.test/pay'];
    }
};

try {
    $response = $controller->store($request, $provider, $reservations);
    if ($sameAttempt && str_ends_with($response->getTargetUrl(), '/checkout')) {
        echo "waiting\n";
        exit(0);
    }
    if ($response->getTargetUrl() !== 'https://example.test/pay') {
        throw new LogicException('Unexpected checkout redirect');
    }
    echo "reserved\n";
} catch (ValidationException $exception) {
    if (! isset($exception->errors()['cart'])) {
        throw $exception;
    }
    echo "out-of-stock\n";
}
