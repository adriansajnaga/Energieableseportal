<?php

namespace App\Enums;

enum SettlementType: string
{
    case Invoice = 'invoice';
    case Cancellation = 'cancellation';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => __('Rechnung'),
            self::Cancellation => __('Stornorechnung'),
        };
    }
}
