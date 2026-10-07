<?php

namespace App\Enums;

enum ReadingSource: string
{
    case TenantQr = 'tenant_qr';
    case Caretaker = 'caretaker';
    case Admin = 'admin';
    case System = 'system';
    case Analyzer = 'analyzer';

    public function label(): string
    {
        return match ($this) {
            self::TenantQr => __('Mieter (QR)'),
            self::Caretaker => __('Hausmeister'),
            self::Admin => __('Verwaltung'),
            self::System => __('System (berechnet)'),
            self::Analyzer => __('Analysator'),
        };
    }
}
