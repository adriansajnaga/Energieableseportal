<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeterAssignment extends Model
{
    use LogsActivity;

    protected $fillable = ['meter_id', 'tenant_id', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Zuordnungen, die sich mit dem Zeitraum [from, to) überschneiden. */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->whereDate('starts_on', '<', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>', $from->toDateString()));
    }
}
