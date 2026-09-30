<?php

namespace App\Enums;

enum SettlementType: string
{
    case Invoice = 'invoice';
    case Collective = 'collective';
    case Cancellation = 'cancellation';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => __('Rechnung'),
            self::Collective => __('Sammelrechnung'),
            self::Cancellation => __('Stornorechnung'),
        };
    }
}
