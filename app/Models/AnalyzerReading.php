<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rohdaten des Analysators (Zeit in UTC). Eindeutig über Gerät, Startnummer (boot), Laufzeit (up) und Platz. */
class AnalyzerReading extends Model
{
    public const REASONS = ['plan', 'start', 'reczny'];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'addr' => 'integer',
            'boot' => 'integer',
            'up' => 'integer',
            'ts' => UtcDateTime::class,
            'ts_reconstructed' => 'boolean',
            'needs_review' => 'boolean',
            'kwh' => 'decimal:2',
            'received_at' => UtcDateTime::class,
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AnalyzerDevice::class, 'device_id');
    }

    /** Zeit für die Anzeige in der Zeitzone des Analysators (Europe/Warsaw). */
    public function localTs(): ?CarbonImmutable
    {
        return $this->ts?->setTimezone(config('energie.analyzer_timezone'));
    }
}
