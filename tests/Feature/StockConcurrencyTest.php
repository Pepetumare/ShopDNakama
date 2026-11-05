<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StockConcurrencyTest extends TestCase
{
    public function test_simultaneous_workers_cannot_oversell_or_overwrite_each_other(): void
    {
        $directory = sys_get_temp_dir().'/nakama-stock-'.Str::uuid();
        mkdir($directory);
        $database = $directory.'/testing.sqlite';
        touch($database);
        $workers = [];
        try {
            $command = [PHP_BINARY, '-d', 'extension=pdo_sqlite', '-d', 'extension=sqlite3'];
            // Only the newly created temporary database is migrated.
            $migration = new Process([...$command, 'artisan', 'migrate', '--force', '--no-interaction'], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '']);
            $migration->mustRun();
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database, 'database.connections.sqlite.url' => null]);
            DB::purge('sqlite');
            $category = Category::create(['name' => 'Concurrencia']);
            $product = Product::create(['category_id' => $category->id, 'name' => 'Últimas unidades', 'price' => 100, 'stock' => 8]);
            $admin = User::factory()->create(['role' => 'admin']);
            foreach ([1, 2] as $worker) {
                $process = new Process([...$command, base_path('tests/Support/stock-worker.php'), $database, (string) $product->id, (string) $admin->id, (string) Str::uuid(), $directory.'/ready'.$worker, $directory.'/go'], base_path(), ['APP_ENV' => 'testing']);
                $process->start();
                $workers[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (! file_exists($directory.'/ready1') || ! file_exists($directory.'/ready2')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Los procesos de prueba no alcanzaron la barrera de concurrencia.');
                }
                usleep(10000);
            }
            file_put_contents($directory.'/go', 'go');
            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = $worker->getOutput();
            }
            sort($results);
            $this->assertSame(['applied', 'rejected'], $results);
            $this->assertSame(2, $product->fresh()->stock);
            $this->assertDatabaseCount('stock_movements', 1);
            $this->assertDatabaseHas('stock_movements', ['before' => 8, 'after' => 2, 'quantity' => -6]);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::disconnect('sqlite');
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
