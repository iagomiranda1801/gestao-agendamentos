<?php

namespace App\Enums;

enum CashAdjustmentType: string
{
    case Reinforcement = 'reinforcement';
    case Withdrawal = 'withdrawal';

    public function label(): string
    {
        return match ($this) {
            self::Reinforcement => 'Reforço',
            self::Withdrawal => 'Sangria',
        };
    }
}
