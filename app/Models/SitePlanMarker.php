<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Zähler auf einem Plan des Objekts, Position in Prozent (0–100) von Breite und Höhe. */
class SitePlanMarker extends Model
{
    protected $fillable = ['site_plan_id', 'meter_id', 'x', 'y'];

    protected function casts(): array
    {
        return ['x' => 'float', 'y' => 'float'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SitePlan::class, 'site_plan_id');
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }
}
