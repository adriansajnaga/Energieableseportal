<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Datum/Zeit, die unabhängig von APP_TIMEZONE in UTC gespeichert und als UTC gelesen wird.
 */
class UtcDateTime implements CastsAttributes
{
    public const FORMAT = 'Y-m-d H:i:s';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : self::format($value);
    }

    public static function format(DateTimeInterface|string|int $value): string
    {
        $date = is_int($value) ? CarbonImmutable::createFromTimestampUTC($value) : CarbonImmutable::parse($value);

        return $date->setTimezone('UTC')->format(self::FORMAT);
    }
}
