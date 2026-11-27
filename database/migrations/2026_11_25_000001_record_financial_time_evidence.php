<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MariaDB commits each ALTER separately. Allow a retry after either statement.
        if (! Schema::hasColumn('stock_reservations', 'absolute_window')) {
            Schema::table('stock_reservations', fn (Blueprint $table) => $table->boolean('absolute_window')->default(false));
        }
        if (! Schema::hasColumn('payment_reconciliation_events', 'provider_date_evidence')) {
            Schema::table('payment_reconciliation_events', fn (Blueprint $table) => $table->json('provider_date_evidence')->nullable());
        }
    }

    public function down(): void
    {
        $events = Schema::hasColumn('payment_reconciliation_events', 'provider_date_evidence');
        $windows = Schema::hasColumn('stock_reservations', 'absolute_window');
        if (($events && DB::table('payment_reconciliation_events')->whereNotNull('provider_date_evidence')->exists())
            || ($windows && DB::table('stock_reservations')->where('absolute_window', true)->exists())) {
            throw new RuntimeException('La reversión eliminaría evidencia de fechas de pagos o reservas.');
        }

        if ($events) {
            Schema::table('payment_reconciliation_events', fn (Blueprint $table) => $table->dropColumn('provider_date_evidence'));
        }
        if ($windows) {
            Schema::table('stock_reservations', fn (Blueprint $table) => $table->dropColumn('absolute_window'));
        }
    }
};
