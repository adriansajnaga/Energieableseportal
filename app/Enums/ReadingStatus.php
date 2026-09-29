<?php

namespace App\Enums;

enum ReadingStatus: string
{
    case Approved = 'approved';
    case Pending = 'pending';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Approved => __('Freigegeben'),
            self::Pending => __('Zu prüfen'),
            self::Rejected => __('Abgelehnt'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Approved => 'green',
            self::Pending => 'amber',
            self::Rejected => 'red',
        };
    }
}
