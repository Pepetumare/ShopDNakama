<?php

namespace App\Support;

class Price
{
    public static function clp(string|int|float $amount): string
    {
        return '$'.number_format((float) $amount, 0, ',', '.');
    }
}
