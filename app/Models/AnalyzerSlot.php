<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Platz 1–6 im Analysator: aktueller Name, Modbus-Adresse, Modell und Zuordnung zu einem Zähler (Messpunkt). */
class AnalyzerSlot extends Model
{
    protected $fillable = ['device_id', 'slot', 'name', 'addr', 'model', 'meter_id', 'meter_since'];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'addr' => 'integer',
            'meter_since' => UtcDateTime::class,
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AnalyzerDevice::class, 'device_id');
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }

    /** Letzte Ablesung dieses Platzes (mit Zeit vor solchen ohne Zeit). */
    public function latestReading(): ?AnalyzerReading
    {
        return AnalyzerReading::query()
            ->where('device_id', $this->device_id)
            ->where('slot', $this->slot)
            ->orderByRaw('ts is null')
            ->orderByDesc('ts')
            ->orderByDesc('id')
            ->first();
    }
}
