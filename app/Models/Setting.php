<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /** Standardwerte, solange in der Datenbank nichts gespeichert ist. */
    public const DEFAULTS = [
        'price_factor' => '1.1',
        'vat_rate' => '19',
        'invoice_prefix' => 'E-',
        'invoice_digits' => '4',
        'invoice_counter' => '0',
        'reading_deadline_day' => '10',
        'reminder_day' => '3',
        'plausibility_multiplier' => '3',
        'landlord_name' => 'Kisin & Bhatti Immobilien GmbH',
        'landlord_street' => 'Kopperpahler Allee 130a',
        'landlord_city' => '24119 Kronshagen',
        'landlord_vat_id' => 'DE353468347',
        'site_address' => "Wrangelstraße\n24539 Neumünster",
        'caretaker_email' => '',
        'datev_revenue_account' => '8400',
        'datev_tax_key' => '',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $values = Cache::rememberForever('settings', fn () => static::query()->pluck('value', 'key')->all());

        return $values[$key] ?? $default ?? static::DEFAULTS[$key] ?? null;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        static::forgetCache();
    }

    public static function forgetCache(): void
    {
        Cache::forget('settings');
    }
}
