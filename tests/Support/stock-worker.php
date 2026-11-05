<?php

use App\Models\Product;
use App\Services\StockManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Isolated test worker; never loads a production database connection.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1], 'database.connections.sqlite.url' => null]);
DB::purge('sqlite');
file_put_contents($argv[5], 'ready');
$deadline = microtime(true) + 10;
while (! file_exists($argv[6])) {
    if (microtime(true) > $deadline) {
        exit(2);
    }
    usleep(10000);
}
try {
    app(StockManager::class)->move(Product::findOrFail($argv[2]), ['type' => 'issue', 'quantity' => 6, 'reason' => 'Salida simultánea de prueba', 'version' => 0, 'token' => $argv[4]], (int) $argv[3]);
    echo 'applied';
} catch (ValidationException) {
    echo 'rejected';
}
