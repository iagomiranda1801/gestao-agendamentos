<?php

namespace App\Support;

class DecimalMoney
{
    public static function round(string $amount, int $scale = 2): string
    {
        if (bccomp($amount, '0', $scale + 4) === 0) {
            return bcadd('0', '0', $scale);
        }

        $half = '0.'.str_repeat('0', $scale).'5';

        if (bccomp($amount, '0', $scale + 4) >= 0) {
            return bcadd($amount, $half, $scale);
        }

        return bcsub($amount, $half, $scale);
    }
}
