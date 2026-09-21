<?php

namespace App\Support;

/** Peso amounts for screens and payslips: "₱1,250.00", and "−₱250.00" for a negative. */
class Money
{
    public static function peso(float|int|string|null $amount): string
    {
        $amount = (float) $amount;

        return ($amount < 0 ? '−' : '').'₱'.number_format(abs($amount), 2);
    }
}
