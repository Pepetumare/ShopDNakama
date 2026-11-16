<?php

use App\Http\Controllers\Housekeeping\OrderController;
use App\Http\Requests\Housekeeping\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\PaymentReconciliation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('NAKAMA_RESERVATION_RACE') !== '1' || config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || DB::connection()->getDatabaseName() !== 'nakama_com01_test') {
    exit(2);
}
$order = Order::findOrFail($argv[1]);
Auth::loginUsingId($argv[2]);
$request = UpdateOrderStatusRequest::create('/', 'PATCH', ['status' => 'enviado']);
$request->setValidator(Validator::make(['status' => 'enviado'], ['status' => 'required']));
DB::connection()->beforeExecuting(function ($query) {
    if (str_contains($query, 'for update') && str_contains($query, 'from `orders`')) {
        echo "waiting-for-order\n";
        flush();
    }
});
try {
    if (($argv[3] ?? '') === 'annotate') {
        DB::listen(function ($event) use ($argv) {
            if (str_contains($event->sql, 'from `payment_transactions`') && str_contains($event->sql, 'for update')) {
                echo "annotation-held\n";
                flush();
                $deadline = microtime(true) + 15;
                while (! is_file($argv[4])) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Synchronization timeout');
                    }
                    usleep(10000);
                }
            }
        });
        $payment = $order->paymentTransactions()->firstOrFail();
        $app->make(PaymentReconciliation::class)->annotate($payment, (int) $argv[2], 'Concurrent observation', false);
        echo "annotated\n";
        exit;
    }
    if (($argv[3] ?? '') === 'resolve') {
        $app->make(PaymentReconciliation::class)->resolveFulfillment($order, User::findOrFail($argv[2]), 'Concurrent resolution');
        exit(3);
    }
    $app->make(OrderController::class)->updateStatus($request, $order);
    exit(($argv[3] ?? '') === 'advance' ? 0 : 3);
} catch (ValidationException $exception) {
    echo "blocked\n";
}
