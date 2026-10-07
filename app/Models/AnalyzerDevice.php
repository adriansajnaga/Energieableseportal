<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Mobiler Analysator (ESP32-S3), meldet sich per Bearer-Token an der API. */
class AnalyzerDevice extends Model
{
    protected $fillable = ['name', 'token_hash', 'fw', 'last_boot', 'last_seen_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'last_boot' => 'integer',
            'last_seen_at' => UtcDateTime::class,
        ];
    }

    public function slots(): HasMany
    {
        return $this->hasMany(AnalyzerSlot::class, 'device_id')->orderBy('slot');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(AnalyzerReading::class, 'device_id');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(?string $token): ?self
    {
        return $token ? static::query()->where('token_hash', static::hashToken($token))->first() : null;
    }

    /**
     * Neues Gerät anlegen. Der Token wird nur hier im Klartext zurückgegeben, gespeichert wird der SHA-256-Hash.
     *
     * @return array{0: self, 1: string}
     */
    public static function register(string $name): array
    {
        $token = static::newToken();

        return [static::create(['name' => $name, 'token_hash' => static::hashToken($token)]), $token];
    }

    public function regenerateToken(): string
    {
        $token = static::newToken();
        $this->update(['token_hash' => static::hashToken($token)]);

        return $token;
    }

    private static function newToken(): string
    {
        return Str::random(48);
    }
}
