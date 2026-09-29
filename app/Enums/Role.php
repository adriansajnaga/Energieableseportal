<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Caretaker = 'caretaker';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Administrator'),
            self::Caretaker => __('Hausmeister'),
            self::Viewer => __('Nur lesen'),
        };
    }
}
