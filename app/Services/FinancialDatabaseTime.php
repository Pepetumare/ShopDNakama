<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;

final class FinancialDatabaseTime
{
    public static function usesSqlTimeZone(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    // Use the transaction's writer PDO even if a caller changed its session zone.
    public static function transaction(Closure $operation): mixed
    {
        return DB::transaction(function () use ($operation) {
            if (! self::usesSqlTimeZone()) {
                return $operation();
            }
            $pdo = DB::connection()->getPdo();
            $previous = $pdo->query('SELECT @@session.time_zone')->fetchColumn();
            if ($previous === '+00:00') {
                return $operation();
            }

            $pdo->exec("SET SESSION time_zone = '+00:00'");
            try {
                return $operation();
            } finally {
                $pdo->exec('SET SESSION time_zone = '.$pdo->quote($previous));
            }
        }, 3);
    }
}
